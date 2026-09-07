<?php

declare(strict_types=1);

namespace App\Domain\Catalog;

use App\Domain\Catalog\Console\ImportCommand;
use App\Domain\Catalog\Console\IndexSearchCommand;
use App\Domain\Catalog\Console\ReconcileCommand;
use App\Domain\Catalog\Console\ReindexCommand;
use App\Domain\Catalog\Console\SeedCommand;
use App\Domain\Catalog\Console\SyncSearchSettingsCommand;
use App\Domain\Catalog\Events\CustomBrandCreated;
use App\Domain\Catalog\Import\GenericResolver;
use App\Domain\Catalog\Listeners\QueueCustomBrandForPromotion;
use App\Domain\Catalog\Policies\CustomBrandPolicy;
use App\Domain\Catalog\Reconcile\SoftReferenceRegistry;
use App\Domain\Catalog\Rules\CatalogIdExists;
use App\Domain\Catalog\Search\CustomBrandIndexSettings;
use App\Domain\Catalog\Search\DoctorUsageBoostProvider;
use App\Domain\Catalog\Search\NullDoctorUsageBoost;
use App\Domain\Catalog\Services\CatalogCache;
use App\Domain\Catalog\Services\CatalogWriteContext;
use App\Domain\Tenancy\Events\TenancyInitialized;
use App\Models\Tenant\CustomBrand;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rule;

final class CatalogServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // config('catalog.*') lives in config/catalog.php (foundation-owned config/ directory, loaded by the framework).

        $this->app->singleton(CatalogWriteContext::class);            // Octane 'flush' recreates it per request
        $this->app->singleton(CatalogCache::class);
        $this->app->singleton(SoftReferenceRegistry::class);
        $this->app->bind(GenericResolver::class, fn () => new GenericResolver((float) config('catalog.import.trigram_threshold', 0.92)));

        if (! $this->app->bound(DoctorUsageBoostProvider::class)) {
            $this->app->bind(DoctorUsageBoostProvider::class, NullDoctorUsageBoost::class);   // Prescription rebinds its Redis-backed one
        }
    }

    public function boot(): void
    {
        Gate::policy(CustomBrand::class, CustomBrandPolicy::class);
        Event::listen(CustomBrandCreated::class, QueueCustomBrandForPromotion::class);
        Event::listen(TenancyInitialized::class, fn () => CustomBrandIndexSettings::register());

        // Rule::catalog('generics') shorthand (CATALOG.md §7).
        Rule::macro('catalog', fn (string $table, bool $requireActive = true) => new CatalogIdExists($table, $requireActive));

        RateLimiter::for('catalog-search', fn (Request $request) => Limit::perSecond(20)->by('catalog-search:'.($request->user('web')?->getAuthIdentifier() ?? $request->ip())));

        if ($this->app->runningInConsole()) {
            $this->commands([
                ImportCommand::class,
                SeedCommand::class,
                IndexSearchCommand::class,
                ReconcileCommand::class,
                SyncSearchSettingsCommand::class,
                ReindexCommand::class,
            ]);
        }
    }
}
