<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Jobs;

use App\Domain\Audit\Enums\CentralAuditAction;
use App\Domain\Catalog\Data\ImportRequest;
use App\Domain\Catalog\Enums\CatalogJobStatus;
use App\Domain\Catalog\Enums\ImportSource;
use App\Domain\Catalog\Import\CatalogImporter;
use App\Domain\Catalog\Search\CatalogSearchIndexer;
use App\Domain\Catalog\Services\CatalogJobs;
use App\Domain\SaaS\Services\CentralAudit;
use App\Domain\Shared\Exceptions\DomainException;
use App\Models\Central\CatalogJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

/**
 * A console-requested import of an uploaded bundle (CATALOG.md §5), dry run or apply, off the request thread.
 * Runs in central context on a worker (`runningInConsole()`, so CatalogWriteContext lets the importer in) and
 * reports through the `catalog_jobs` row: step progress while it runs, the importer's report when it is done,
 * the error when it is not. Never retried by the queue — the checksum guard would refuse a second apply anyway,
 * and a failed dry run is a report, not an outage. After an applied run the documents the version touched are
 * upserted into Meilisearch (CATALOG.md §5.7) so search follows the catalogue without a full rebuild.
 */
final class RunCatalogImport implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(public readonly int $jobId)
    {
        $this->onQueue('default');
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('catalog-import'))->expireAfter(3600)->dontRelease()];
    }

    public function handle(CatalogImporter $importer, CatalogJobs $jobs, CentralAudit $audit): void
    {
        $job = CatalogJob::query()->find($this->jobId);

        if ($job === null || $job->status !== CatalogJobStatus::Queued || $job->bundle_path === null) {
            return;
        }

        $jobs->start($job, ['step' => 'starting', 'percent' => 0]);
        $dryRun = $job->mode === 'dry_run';
        $adminEmail = $job->requestedBy?->email;

        $importer->onProgress(function (string $step, int $index, int $count) use ($jobs, $job): void {
            $jobs->progress($job, ['step' => $step, 'percent' => (int) floor(($index - 1) / $count * 95)]);
        });

        try {
            $report = $importer->run(new ImportRequest(
                path: $job->bundle_path,
                source: ImportSource::tryFrom((string) $job->source) ?? ImportSource::Manual,
                version: $job->version,
                full: $job->full,
                dryRun: $dryRun,
                force: (bool) ($job->progress['force'] ?? false),
                reindex: false,
                appliedBy: $adminEmail !== null ? $adminEmail : 'super-admin:'.$job->requested_by_super_admin_id,
                releaseRef: $job->release_ref,
                notes: ($dryRun ? 'console dry run' : 'console import').' of '.implode(', ', $job->bundle_files),
            ));
        } catch (DomainException $e) {
            $jobs->fail($job, $e->getMessage(), $e->code());

            return;
        } catch (Throwable $e) {
            report($e);
            $jobs->fail($job, $e->getMessage());

            return;
        } finally {
            $importer->onProgress(null);
        }

        $result = $report->toArray();

        if ($report->isApplied()) {
            $jobs->progress($job, ['step' => 'indexing', 'percent' => 96]);
            $result['indexed'] = $this->reindex($report->changedIds, $result);

            $audit->record(CentralAuditAction::Create, null, $job, null, [
                'kind' => 'import', 'applied' => true, 'version' => $report->version, 'changes' => $report->totalChanges(), 'issues' => $report->issues,
            ], $job->requested_by_super_admin_id);
        }

        // A dry run's version row was rolled back with everything else: the job must not point at it.
        $jobs->succeed($job, $result, $report->isApplied() ? $report->versionId : null);
    }

    /**
     * @param  array<string, list<int>>  $changed
     * @param  array<string, mixed>  $result
     */
    private function reindex(array $changed, array &$result): bool
    {
        if (config('scout.driver') !== 'meilisearch') {
            return false;
        }

        try {
            app(CatalogSearchIndexer::class)->upsertChanged($changed);

            return true;
        } catch (Throwable $e) {
            report($e);
            $result['index_error'] = $e->getMessage();      // the import stands; the operator can rebuild the index

            return false;
        }
    }
}
