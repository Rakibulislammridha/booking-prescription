<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Jobs;

use App\Domain\Catalog\Enums\CatalogJobStatus;
use App\Domain\Catalog\Search\CatalogSearchIndexer;
use App\Domain\Catalog\Services\CatalogJobs;
use App\Models\Central\CatalogJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Throwable;

/**
 * The console's "rebuild search index" button: `catalog:index-search --fresh` as a Horizon job on the `search`
 * queue (ARCHITECTURE §4.6), with the zero-downtime `_next` + swap of CATALOG.md §4.4 — a doctor typing "nap"
 * mid-rebuild still gets Napa. Progress and document counts go to the `catalog_jobs` row.
 */
final class RebuildCatalogSearchIndex implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 1800;

    public function __construct(public readonly int $jobId)
    {
        $this->onQueue('search');
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('catalog-reindex'))->expireAfter(3600)->dontRelease()];
    }

    public function handle(CatalogSearchIndexer $indexer, CatalogJobs $jobs): void
    {
        $job = CatalogJob::query()->find($this->jobId);

        if ($job === null || $job->status !== CatalogJobStatus::Queued) {
            return;
        }

        $jobs->start($job, ['step' => 'starting', 'percent' => 0]);

        if (config('scout.driver') !== 'meilisearch') {
            $jobs->fail($job, 'scout.driver is not meilisearch (SCOUT_DRIVER); nothing to index.', 'catalog.search_unavailable');

            return;
        }

        $report = ['indexes' => []];
        $indexes = [CatalogSearchIndexer::DRUGS, CatalogSearchIndexer::ICD10];

        try {
            foreach ($indexes as $i => $index) {
                $jobs->progress($job, ['step' => $index, 'percent' => (int) ($i / count($indexes) * 90)]);
                $started = hrtime(true);
                $count = $indexer->rebuild($index);
                $report['indexes'][$indexer->uid($index)] = ['documents' => $count, 'ms' => (int) ((hrtime(true) - $started) / 1_000_000)];
            }
        } catch (Throwable $e) {
            report($e);
            $jobs->fail($job, $e->getMessage(), 'catalog.reindex_failed');

            return;
        }

        $jobs->succeed($job, $report);
    }
}
