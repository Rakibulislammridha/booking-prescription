<?php

declare(strict_types=1);

namespace App\Domain\Billing;

use App\Domain\Billing\Console\CollectHammerCommand;
use App\Domain\Billing\Console\CouponHammerCommand;
use App\Domain\Billing\Gateways\GatewayManager;
use App\Domain\Billing\Listeners\CreateInvoiceForBooking;
use App\Domain\Billing\Listeners\DecideRefundOnCancellation;
use App\Domain\Billing\Listeners\RecordNoShowOnInvoice;
use App\Domain\Billing\Listeners\VoidPendingRefund;
use App\Domain\Billing\Policies\CashShiftPolicy;
use App\Domain\Billing\Policies\CouponPolicy;
use App\Domain\Billing\Policies\DoctorRevenueSharePolicy;
use App\Domain\Billing\Policies\InvoicePolicy;
use App\Domain\Billing\Services\BillingOnlinePaymentGateway;
use App\Domain\Billing\Services\BillingRefundProcessor;
use App\Domain\Billing\Services\CashCollectorService;
use App\Domain\Billing\Services\RevenueShareResolver;
use App\Domain\Booking\Contracts\OnlinePaymentGateway;
use App\Domain\Booking\Contracts\RefundProcessor;
use App\Domain\Booking\Events\AppointmentBooked;
use App\Domain\Reception\Contracts\CashCollector;
use App\Domain\Serials\Events\SerialCancelled;
use App\Domain\Serials\Events\SerialNoShow;
use App\Domain\Serials\Events\SerialReinstatedAfterCancel;
use App\Models\Tenant\CashShift;
use App\Models\Tenant\Coupon;
use App\Models\Tenant\DoctorRevenueShare;
use App\Models\Tenant\Invoice;
use App\Tenancy\Facades\Tenancy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Billing module wiring. It takes over the three seams other modules were built against:
 *
 *   Reception\Contracts\CashCollector       NullCashCollector   → CashCollectorService (real payments rows)
 *   Booking\Contracts\RefundProcessor       NullRefundProcessor → BillingRefundProcessor (real refunds rows)
 *   Booking\Contracts\OnlinePaymentGateway  NoOnlinePayment     → BillingOnlinePaymentGateway (bKash/Nagad/SSL)
 *
 * The bindings are `singleton`/`bind`, not `bindIf`, because those modules registered their nulls with `bindIf`
 * and this provider is listed after them in bootstrap/providers.php.
 */
final class BillingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(CashCollector::class, CashCollectorService::class);
        $this->app->bind(RefundProcessor::class, BillingRefundProcessor::class);
        $this->app->bind(OnlinePaymentGateway::class, BillingOnlinePaymentGateway::class);

        // Per-tenant credential and rule caches: scoped, and flushed when tenancy changes (Octane §4.5).
        $this->app->scoped(GatewayManager::class);
        $this->app->scoped(RevenueShareResolver::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([CollectHammerCommand::class, CouponHammerCommand::class]);
        }

        Gate::policy(Invoice::class, InvoicePolicy::class);
        Gate::policy(Coupon::class, CouponPolicy::class);
        Gate::policy(CashShift::class, CashShiftPolicy::class);
        Gate::policy(DoctorRevenueShare::class, DoctorRevenueSharePolicy::class);

        Event::listen(AppointmentBooked::class, CreateInvoiceForBooking::class);
        Event::listen(SerialCancelled::class, DecideRefundOnCancellation::class);
        Event::listen(SerialNoShow::class, RecordNoShowOnInvoice::class);
        Event::listen(SerialReinstatedAfterCancel::class, VoidPendingRefund::class);

        // Gateway callbacks are unauthenticated by nature; throttle per tenant and per remote address so a
        // flood cannot be used to probe merchant references.
        RateLimiter::for('billing-webhook', fn (Request $request) => [
            Limit::perMinute(120)->by('billing-webhook:'.(string) (Tenancy::id() ?? 'central').':'.$request->ip()),
        ]);

        RateLimiter::for('billing-checkout', fn (Request $request) => [
            Limit::perMinute(10)->by('billing-checkout:'.(string) (Tenancy::id() ?? 'central').':'.$request->ip()),
        ]);
    }
}
