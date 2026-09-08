<?php

declare(strict_types=1);

namespace App\Domain\Booking;

use App\Domain\Booking\Console\ExpireAdvancePaymentHoldsCommand;
use App\Domain\Booking\Contracts\OnlinePaymentGateway;
use App\Domain\Booking\Contracts\RefundProcessor;
use App\Domain\Booking\Listeners\CreateDraftFollowUpAppointment;
use App\Domain\Booking\Listeners\RepointAppointmentOnPostpone;
use App\Domain\Booking\Listeners\RepointAppointmentOnTransfer;
use App\Domain\Booking\Listeners\SyncAppointmentWithSerial;
use App\Domain\Booking\Policies\AppointmentPolicy;
use App\Domain\Booking\Services\NoOnlinePayment;
use App\Domain\Booking\Services\NullRefundProcessor;
use App\Domain\Serials\Events\SerialPostponed;
use App\Domain\Serials\Events\SerialStatusChanged;
use App\Domain\Serials\Events\SerialTransferred;
use App\Models\Tenant\Appointment;
use App\Tenancy\Facades\Tenancy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Booking module wiring: the appointment ↔ serial listeners, the follow-up draft listener (by event class name — the
 * Prescription module ships concurrently), the `booking` / `kiosk` rate limiters (SERIAL_ENGINE §11.2, §16), the
 * advance-payment hold sweeper (BRIEF §5.C) and the pay-at-counter / null-refund bindings Billing later rebinds.
 */
final class BookingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bindIf(OnlinePaymentGateway::class, NoOnlinePayment::class);
        $this->app->bindIf(RefundProcessor::class, NullRefundProcessor::class);
    }

    public function boot(): void
    {
        Gate::policy(Appointment::class, AppointmentPolicy::class);

        Event::listen(SerialStatusChanged::class, SyncAppointmentWithSerial::class);
        Event::listen(SerialPostponed::class, RepointAppointmentOnPostpone::class);
        Event::listen(SerialTransferred::class, RepointAppointmentOnTransfer::class);
        Event::listen(CreateDraftFollowUpAppointment::EVENT, CreateDraftFollowUpAppointment::class);

        $mobile = fn (Request $request): string => preg_replace('/\D/', '', (string) $request->input('mobile', '')) ?? '';

        RateLimiter::for('booking', fn (Request $request) => [
            Limit::perMinute(20)->by('booking-ip:'.(string) (Tenancy::id() ?? 'central').':'.$request->ip()),
            Limit::perMinute(5)->by('booking-mobile:'.(string) (Tenancy::id() ?? 'central').':'.$mobile($request)),
        ]);

        RateLimiter::for('kiosk', fn (Request $request) => [
            Limit::perMinute(5)->by('kiosk-mobile:'.(string) (Tenancy::id() ?? 'central').':'.$mobile($request)),
            Limit::perMinute(60)->by('kiosk-branch:'.(string) (Tenancy::id() ?? 'central').':'.(string) $request->input('branch', $request->ip())),
        ]);

        if ($this->app->runningInConsole()) {
            $this->commands([ExpireAdvancePaymentHoldsCommand::class]);
        }
    }
}
