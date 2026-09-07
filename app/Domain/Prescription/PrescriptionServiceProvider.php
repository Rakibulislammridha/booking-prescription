<?php

declare(strict_types=1);

namespace App\Domain\Prescription;

use App\Domain\Catalog\Reconcile\SoftReferenceRegistry;
use App\Domain\Catalog\Search\DoctorUsageBoostProvider;
use App\Domain\Catalog\Services\CatalogCache;
use App\Domain\Patients\Services\TimelineSourceRegistry;
use App\Domain\Prescription\AI\AiAssistant;
use App\Domain\Prescription\AI\HttpChatAssistant;
use App\Domain\Prescription\AI\NullAiAssistant;
use App\Domain\Prescription\Console\RecomputeFavouritesCommand;
use App\Domain\Prescription\Events\PrescriptionIssued;
use App\Domain\Prescription\Listeners\CompleteConsultationOnPrescriptionIssued;
use App\Domain\Prescription\Listeners\QueuePrescriptionPdf;
use App\Domain\Prescription\Listeners\RecordDoctorUsage;
use App\Domain\Prescription\Listeners\StartVisitOnSerialCalled;
use App\Domain\Prescription\Policies\AdviceSnippetPolicy;
use App\Domain\Prescription\Policies\PrescriptionPolicy;
use App\Domain\Prescription\Policies\PrescriptionTemplatePolicy;
use App\Domain\Prescription\Policies\VisitPolicy;
use App\Domain\Prescription\Safety\SafetyPipeline;
use App\Domain\Prescription\Services\DoctorLearningCache;
use App\Domain\Prescription\Services\DoctorUsageBoost;
use App\Domain\Prescription\Services\PrescriptionChannelGuard;
use App\Domain\Prescription\Sources\PrescriptionsTimelineSource;
use App\Domain\Prescription\Sources\VisitsTimelineSource;
use App\Domain\Prescription\Sources\VitalsTimelineSource;
use App\Domain\Serials\Events\SerialCalled;
use App\Models\Tenant\AdviceSnippet;
use App\Models\Tenant\Prescription;
use App\Models\Tenant\PrescriptionTemplate;
use App\Models\Tenant\Visit;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/** The module's single provider (CONVENTIONS §12): bindings, policies, listeners, timeline sources, limiters, commands. */
final class PrescriptionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SafetyPipeline::class, function ($app): SafetyPipeline {
            $checks = array_map(fn (string $class) => $app->make($class), (array) config('prescription.safety.checks', []));

            return new SafetyPipeline($checks, $app->make(CatalogCache::class));
        });

        $this->app->singleton(DoctorLearningCache::class);
        $this->app->bind(DoctorUsageBoostProvider::class, DoctorUsageBoost::class);   // replaces Catalog's NullDoctorUsageBoost

        $this->app->bind(AiAssistant::class, function (): AiAssistant {
            $key = (string) config('services.ai.key', '');
            $baseUrl = (string) config('services.ai.base_url', '');

            if ($key === '' || $baseUrl === '') {
                return new NullAiAssistant;
            }

            return new HttpChatAssistant($baseUrl, $key, (string) config('services.ai.model', 'gpt-4o-mini'), (int) config('prescription.ai.timeout', 8), (string) config('services.ai.provider', 'http'));
        });
    }

    public function boot(): void
    {
        Gate::policy(Visit::class, VisitPolicy::class);
        Gate::policy(Prescription::class, PrescriptionPolicy::class);
        Gate::policy(PrescriptionTemplate::class, PrescriptionTemplatePolicy::class);
        Gate::policy(AdviceSnippet::class, AdviceSnippetPolicy::class);

        Event::listen(SerialCalled::class, StartVisitOnSerialCalled::class);
        Event::listen(PrescriptionIssued::class, RecordDoctorUsage::class);
        Event::listen(PrescriptionIssued::class, CompleteConsultationOnPrescriptionIssued::class);
        Event::listen(PrescriptionIssued::class, QueuePrescriptionPdf::class);          // §7.5 — queues the PDF on Horizon's `pdf` queue

        $timeline = $this->app->make(TimelineSourceRegistry::class);

        foreach ([VisitsTimelineSource::class, PrescriptionsTimelineSource::class, VitalsTimelineSource::class] as $source) {
            $timeline->register($source);
        }

        $refs = $this->app->make(SoftReferenceRegistry::class);
        $refs->register('prescription_items', 'generic_id', 'generics', snapshotColumn: 'generic_name');
        $refs->register('prescription_items', 'brand_id', 'brands', snapshotColumn: 'brand_name', genericColumn: 'generic_id');
        $refs->register('prescription_items', 'strength_id', 'strengths', snapshotColumn: 'strength', brandColumn: 'brand_id', genericColumn: 'generic_id');
        $refs->register('prescription_template_items', 'generic_id', 'generics', snapshotColumn: 'generic_name');
        $refs->register('prescription_template_items', 'brand_id', 'brands', snapshotColumn: 'brand_name', genericColumn: 'generic_id');
        $refs->register('prescription_template_items', 'strength_id', 'strengths', snapshotColumn: 'strength', brandColumn: 'brand_id', genericColumn: 'generic_id');
        $refs->register('doctor_favourites', 'generic_id', 'generics', snapshotColumn: 'label');
        $refs->register('doctor_drug_usage', 'generic_id', 'generics');

        // The writer tab's PdfReady channel (§7.5). routes/channels.php delegates it to this module by name.
        $channelGuard = $this->app->make(PrescriptionChannelGuard::class);
        Broadcast::channel(
            'tenant.{tenant}.prescription.{prescription}',
            fn (?Authenticatable $auth, string $tenant, string $prescription) => $channelGuard->view($auth, $tenant, $prescription),
            ['guards' => ['web', 'sanctum']],
        );

        RateLimiter::for('rx-verify', fn (Request $request) => Limit::perMinute((int) config('prescription.verify_throttle_per_minute', 30))->by('rx-verify:'.$request->ip()));
        RateLimiter::for('prescription-search', fn (Request $request) => Limit::perSecond((int) config('prescription.search_throttle_per_second', 20))->by('rx-search:'.($request->user('web')?->getAuthIdentifier() ?? $request->ip())));

        if ($this->app->runningInConsole()) {
            $this->commands([RecomputeFavouritesCommand::class]);
        }
    }
}
