<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Console;

use App\Models\Tenant\CustomBrand;
use App\Tenancy\Console\ResolvesTenants;
use App\Tenancy\Facades\Tenancy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Throwable;

/**
 * tenants:reindex {--tenant=*} {--model=} — scout:import for the tenant's Searchable models inside Tenancy::run
 * (ARCHITECTURE.md §4.3). Models register themselves in config('catalog.searchable_models'); CustomBrand is built in.
 */
final class ReindexCommand extends Command
{
    use ResolvesTenants;

    protected $signature = 'tenants:reindex {--tenant=* : ids or slugs} {--model= : Only this model class}';

    protected $description = 'Rebuild the tenant search documents (custom brands, patients …) through scout:import';

    public function handle(): int
    {
        $models = array_values(array_unique(array_merge([CustomBrand::class], (array) config('catalog.searchable_models', []))));
        $only = $this->option('model');

        if (is_string($only) && $only !== '') {
            $models = array_values(array_filter($models, fn ($m) => $m === $only || class_basename($m) === $only));
        }

        $failed = 0;

        foreach ($this->resolveTenants() as $tenant) {
            try {
                Tenancy::run($tenant, function () use ($models): void {
                    foreach ($models as $model) {
                        if (class_exists($model)) {
                            Artisan::call('scout:import', ['model' => $model], $this->output);
                        }
                    }
                });
                $this->components->info("Tenant #{$tenant->id} [{$tenant->slug}]: reindexed ".count($models).' model(s)');
            } catch (Throwable $e) {
                $failed++;
                $this->components->error("Tenant #{$tenant->id} [{$tenant->slug}]: {$e->getMessage()}");
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
