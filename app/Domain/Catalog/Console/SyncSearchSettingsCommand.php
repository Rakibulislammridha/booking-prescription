<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Console;

use App\Domain\Catalog\Search\CustomBrandIndexSettings;
use App\Domain\Catalog\Search\MeilisearchIndexes;
use App\Tenancy\Console\ResolvesTenants;
use App\Tenancy\Facades\Tenancy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Throwable;

/**
 * tenants:sync-search-settings {--tenant=*} — registers CustomBrandIndexSettings under scout.meilisearch.index-settings
 * for the tenant's uid and runs scout:sync-index-settings (CATALOG.md §4.2); ensures the index exists first.
 */
final class SyncSearchSettingsCommand extends Command
{
    use ResolvesTenants;

    protected $signature = 'tenants:sync-search-settings {--tenant=* : ids or slugs}';

    protected $description = 'Apply the custom-brand / patient index settings to every tenant Meilisearch index';

    public function handle(MeilisearchIndexes $indexes): int
    {
        if (config('scout.driver') !== 'meilisearch') {
            $this->components->error('scout.driver is not meilisearch; nothing to sync.');

            return self::FAILURE;
        }

        $failed = 0;

        foreach ($this->resolveTenants() as $tenant) {
            try {
                $indexes->ensureTenantIndexes($tenant);
                Tenancy::run($tenant, function (): void {
                    CustomBrandIndexSettings::register();
                    Artisan::call('scout:sync-index-settings', [], $this->output);
                });
                $this->components->info("Tenant #{$tenant->id} [{$tenant->slug}]: settings synced");
            } catch (Throwable $e) {
                $failed++;
                $this->components->error("Tenant #{$tenant->id} [{$tenant->slug}]: {$e->getMessage()}");
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
