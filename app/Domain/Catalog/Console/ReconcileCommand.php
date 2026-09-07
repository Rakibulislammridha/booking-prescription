<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Console;

use App\Domain\Catalog\Events\CatalogReconciliationCompleted;
use App\Domain\Catalog\Jobs\ReconcileCatalogReferences;
use App\Models\Central\CatalogReconciliationReport;
use App\Models\Central\Tenant;
use App\Tenancy\Console\ResolvesTenants;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * catalog:reconcile {--tenant=*} {--since=} — central context; fans out one ReconcileCatalogReferences job per active
 * tenant (CATALOG.md §6). With the sync queue (tests, dev) the jobs run inline and the summary event fires here.
 */
final class ReconcileCommand extends Command
{
    use ResolvesTenants;

    protected $signature = 'catalog:reconcile {--tenant=* : ids or slugs (default: every active tenant)} {--since= : only tenants created/updated since this date (YYYY-MM-DD)}';

    protected $description = 'Nightly scan of every tenant schema for soft references to catalog rows that no longer resolve';

    public function handle(): int
    {
        $runId = (string) Str::ulid();
        $since = $this->option('since');
        $count = 0;

        foreach ($this->resolveTenants() as $tenant) {
            /** @var Tenant $tenant */
            $updatedAt = $tenant->getAttribute('updated_at');

            if (is_string($since) && $since !== '' && $updatedAt instanceof \DateTimeInterface && CarbonImmutable::instance($updatedAt)->lt(CarbonImmutable::parse($since))) {
                continue;
            }

            dispatch((new ReconcileCatalogReferences($runId))->forTenant($tenant));
            $count++;
        }

        $rows = CatalogReconciliationReport::query()->where('run_id', $runId);
        $orphans = (int) (clone $rows)->sum('orphan_count');
        $inactive = (int) (clone $rows)->where('status', 'inactive_found')->count();

        if ((clone $rows)->exists()) {
            event(new CatalogReconciliationCompleted($runId, $count, $orphans, $inactive));
        }

        $this->components->info("Reconcile run {$runId}: {$count} tenant job(s) dispatched; orphans so far: {$orphans}.");

        return self::SUCCESS;
    }
}
