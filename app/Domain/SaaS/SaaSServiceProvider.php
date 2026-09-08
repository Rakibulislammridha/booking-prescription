<?php

declare(strict_types=1);

namespace App\Domain\SaaS;

use App\Domain\Catalog\Events\CatalogReconciliationCompleted;
use App\Domain\Notifications\Events\NotificationDeadLettered;
use App\Domain\Prescription\Events\PrescriptionIssued;
use App\Domain\SaaS\Console\DunSubscriptionsCommand;
use App\Domain\SaaS\Console\LimitHammerCommand;
use App\Domain\SaaS\Console\PruneImpersonationTokensCommand;
use App\Domain\SaaS\Console\RecountUsageCommand;
use App\Domain\SaaS\Console\RenewSubscriptionsCommand;
use App\Domain\SaaS\Console\TenantsBackupCommand;
use App\Domain\SaaS\Console\TenantsExportCommand;
use App\Domain\SaaS\Console\TenantsRestoreCommand;
use App\Domain\SaaS\Console\VerifyDomainsCommand;
use App\Domain\SaaS\Contracts\DnsResolver;
use App\Domain\SaaS\Events\DunningNoticeDue;
use App\Domain\SaaS\Events\SubscriptionPaymentReceived;
use App\Domain\SaaS\Events\TenantAutoSuspended;
use App\Domain\SaaS\Gateways\SubscriptionGatewayManager;
use App\Domain\SaaS\Listeners\FlushEntitlementsCache;
use App\Domain\SaaS\Listeners\NotifyCatalogReconciliation;
use App\Domain\SaaS\Listeners\NotifySuspension;
use App\Domain\SaaS\Listeners\PrimeFeatureFlags;
use App\Domain\SaaS\Listeners\ReactivateOnPayment;
use App\Domain\SaaS\Listeners\RecordPrescriptionUsage;
use App\Domain\SaaS\Listeners\RelaxLimitsForTestFixtures;
use App\Domain\SaaS\Listeners\ReleaseSmsCreditsOnDeadLetter;
use App\Domain\SaaS\Listeners\SendDunningNotice;
use App\Domain\SaaS\Listeners\SendTenantWelcome;
use App\Domain\SaaS\Observers\AppointmentUsageObserver;
use App\Domain\SaaS\Observers\BranchUsageObserver;
use App\Domain\SaaS\Observers\DoctorUsageObserver;
use App\Domain\SaaS\Observers\NotificationUsageObserver;
use App\Domain\SaaS\Observers\PatientDocumentUsageObserver;
use App\Domain\SaaS\Services\ArrayDnsResolver;
use App\Domain\SaaS\Services\Entitlements;
use App\Domain\SaaS\Services\PlanLimits;
use App\Domain\SaaS\Services\SystemDnsResolver;
use App\Domain\Tenancy\Events\TenancyEnded;
use App\Domain\Tenancy\Events\TenancyInitialized;
use App\Domain\Tenancy\Events\TenantProvisioned;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Branch;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\Notification;
use App\Models\Tenant\PatientDocument;
use App\Tenancy\Facades\Tenancy;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * The SaaS control plane's wiring (BRIEF §5.M).
 *
 * The interesting decision is the OBSERVERS. ARCHITECTURE §8.4 sketches plan limits as checks inside
 * `CreateDoctor` / `CreateBranch` / `BookSerial` / `SendSms`, and §5.4 meters usage from after-commit listeners.
 * Neither is enough on its own: an after-commit listener cannot refuse anything, and a check inside one Action is
 * only as strong as the number of write paths that go through that Action — the offline replay, a seeder, an
 * import and a console command all bypass it. Registering the gate on `Model::creating` puts it on EVERY Eloquent
 * write path in the process, inside the caller's transaction, and the atomic
 * `INSERT … ON CONFLICT DO UPDATE … RETURNING` in `UsageMeter` makes it correct under concurrency. The
 * documented listeners survive where they are right — uncapped metering (`prescriptions`) and giving credits back.
 */
final class SaaSServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Per-tenant plan resolution: memoised for one request/job/Octane operation, never longer.
        $this->app->scoped(Entitlements::class);
        $this->app->scoped(PlanLimits::class);
        $this->app->scoped(SubscriptionGatewayManager::class);

        // The suite must never reach a resolver; ArrayDnsResolver is a singleton there so a test can publish records.
        if ($this->app->environment('testing')) {
            $this->app->singleton(ArrayDnsResolver::class);
            $this->app->bind(DnsResolver::class, ArrayDnsResolver::class);
        } else {
            $this->app->bind(DnsResolver::class, SystemDnsResolver::class);
        }
    }

    public function boot(): void
    {
        $this->registerUsageObservers();
        $this->registerListeners();
        $this->registerRateLimiters();

        if ($this->app->runningInConsole()) {
            $this->commands([
                RenewSubscriptionsCommand::class,
                DunSubscriptionsCommand::class,
                RecountUsageCommand::class,
                VerifyDomainsCommand::class,
                PruneImpersonationTokensCommand::class,
                TenantsBackupCommand::class,
                TenantsExportCommand::class,
                TenantsRestoreCommand::class,
            ]);

            if (! $this->app->isProduction()) {
                $this->commands([LimitHammerCommand::class]);
            }
        }
    }

    /**
     * The plan gate, on the five models a plan actually meters. Every hook is a no-op without an active tenancy,
     * so provisioning (which creates the first branch before the tenant is servable) and central work are unaffected.
     */
    private function registerUsageObservers(): void
    {
        Branch::observe(BranchUsageObserver::class);
        Doctor::observe(DoctorUsageObserver::class);
        Appointment::observe(AppointmentUsageObserver::class);
        PatientDocument::observe(PatientDocumentUsageObserver::class);
        Notification::observe(NotificationUsageObserver::class);
    }

    private function registerListeners(): void
    {
        Event::listen(TenantProvisioned::class, SendTenantWelcome::class);
        Event::listen(TenantProvisioned::class, RelaxLimitsForTestFixtures::class);
        Event::listen(TenantProvisioned::class, PrimeFeatureFlags::class);   // last: it caches what the two above decided
        Event::listen(DunningNoticeDue::class, SendDunningNotice::class);
        Event::listen(TenantAutoSuspended::class, NotifySuspension::class);
        Event::listen(SubscriptionPaymentReceived::class, ReactivateOnPayment::class);
        Event::listen(PrescriptionIssued::class, RecordPrescriptionUsage::class);
        Event::listen(NotificationDeadLettered::class, ReleaseSmsCreditsOnDeadLetter::class);
        Event::listen(CatalogReconciliationCompleted::class, NotifyCatalogReconciliation::class);

        // Per-tenant memos must not survive a tenancy switch inside one request (the super console does that).
        Event::listen(TenancyInitialized::class, FlushEntitlementsCache::class);
        Event::listen(TenancyEnded::class, FlushEntitlementsCache::class);
    }

    private function registerRateLimiters(): void
    {
        // Sign-up provisions a Postgres schema and runs every tenant migration: it is by far the most expensive
        // unauthenticated request in the system, so it is throttled hard, per IP.
        RateLimiter::for('saas-signup', fn (Request $request) => [Limit::perHour(5)->by('saas-signup:'.$request->ip())]);

        // A DNS check is cheap for us and slow for the resolver; a "check now" button must not become a probe.
        RateLimiter::for('saas-domain-verify', fn (Request $request) => [
            Limit::perMinute(10)->by('saas-domain-verify:'.(string) (Tenancy::id() ?? 'central').':'.$request->ip()),
        ]);
    }
}
