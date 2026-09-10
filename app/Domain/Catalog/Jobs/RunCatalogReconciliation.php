<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Jobs;

use App\Domain\Catalog\Enums\CatalogJobStatus;
use App\Domain\Catalog\Services\CatalogJobs;
use App\Models\Central\CatalogJob;
use App\Models\Central\Tenant;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Str;
use Throwable;

/**
 * The console's "run reconciliation now": what `catalog:reconcile` does at 02:00 (CATALOG.md §6), started from a
 * button. It fans out one `ReconcileCatalogReferences` per active tenant under a fresh run id and records that
 * run id on the `catalog_jobs` row; the per-tenant jobs then write their `catalog_reconciliation_reports` rows
 * as they finish, which the reconciliation page lists by run — so the console shows the sweep filling in rather
 * than a spinner over a ten-minute job.
 */
final class RunCatalogReconciliation implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public function __construct(public readonly int $jobId)
    {
        $this->onQueue('default');
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('catalog-reconcile-run'))->expireAfter(1800)->dontRelease()];
    }

    public function handle(CatalogJobs $jobs): void
    {
        $job = CatalogJob::query()->find($this->jobId);

        if ($job === null || $job->status !== CatalogJobStatus::Queued) {
            return;
        }

        $runId = (string) Str::ulid();
        $jobs->start($job, ['step' => 'dispatching', 'percent' => 10, 'run_id' => $runId]);

        try {
            $tenants = Tenant::query()->active()->orderBy('id')->get();

            foreach ($tenants as $tenant) {
                dispatch((new ReconcileCatalogReferences($runId))->forTenant($tenant));
            }
        } catch (Throwable $e) {
            report($e);
            $jobs->fail($job, $e->getMessage(), 'catalog.reconcile_failed');

            return;
        }

        $jobs->succeed($job, ['run_id' => $runId, 'tenants' => $tenants->count(), 'tenant_ids' => $tenants->pluck('id')->all()]);
    }
}
