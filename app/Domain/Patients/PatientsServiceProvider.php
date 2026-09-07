<?php

declare(strict_types=1);

namespace App\Domain\Patients;

use App\Domain\Patients\Contracts\DocumentNamer;
use App\Domain\Patients\Contracts\OtpSender;
use App\Domain\Patients\Contracts\PatientAccessResolver;
use App\Domain\Patients\Policies\PatientPolicy;
use App\Domain\Patients\Services\DefaultPatientAccessResolver;
use App\Domain\Patients\Services\LogOtpSender;
use App\Domain\Patients\Services\NullDocumentNamer;
use App\Domain\Patients\Services\TimelineSourceRegistry;
use App\Domain\Patients\Sources\AllergiesTimelineSource;
use App\Domain\Patients\Sources\ConditionsTimelineSource;
use App\Domain\Patients\Sources\ConsentsTimelineSource;
use App\Domain\Patients\Sources\DocumentsTimelineSource;
use App\Domain\Patients\Sources\MedicationsTimelineSource;
use App\Models\Tenant\Patient;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Patients module wiring. Other modules extend it without editing it: bind OtpSender (Notifications), rebind
 * PatientAccessResolver (Prescription), register PatientTimelineSource classes (Prescription, Billing, Serials).
 */
final class PatientsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // config('patients.*') lives in config/patients.php (foundation-owned config/ directory, loaded by the framework).

        $this->app->singleton(TimelineSourceRegistry::class);
        $this->app->bindIf(OtpSender::class, LogOtpSender::class);
        $this->app->bindIf(DocumentNamer::class, NullDocumentNamer::class);
        $this->app->bindIf(PatientAccessResolver::class, DefaultPatientAccessResolver::class);
    }

    public function boot(): void
    {
        Gate::policy(Patient::class, PatientPolicy::class);

        $registry = $this->app->make(TimelineSourceRegistry::class);

        foreach ([DocumentsTimelineSource::class, AllergiesTimelineSource::class, ConditionsTimelineSource::class, MedicationsTimelineSource::class, ConsentsTimelineSource::class] as $source) {
            $registry->register($source);
        }

        // throttle:otp — per IP and per mobile (ARCHITECTURE §6.3; CONVENTIONS §5).
        RateLimiter::for('otp', fn (Request $request) => [
            Limit::perMinute(5)->by('otp-ip:'.$request->ip()),
            Limit::perHour(10)->by('otp-mobile:'.preg_replace('/\D/', '', (string) $request->input('mobile', ''))),
        ]);
    }
}
