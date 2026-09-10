<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Queries;

use App\Domain\Catalog\Enums\CatalogJobKind;
use App\Domain\Catalog\Search\CatalogSearchIndexer;
use App\Domain\Catalog\Services\CatalogCache;
use App\Models\Catalog\CatalogImportIssue;
use App\Models\Catalog\CatalogVersion;
use App\Models\Central\CatalogJob;
use Illuminate\Support\Facades\DB;
use Meilisearch\Client;

/**
 * What the console's Imports page shows: the catalogue's release history (`catalog_versions` — who applied what
 * and when), the console's own job history (`catalog_jobs` — uploads, dry runs, applies, rebuilds, sweeps with
 * their progress), the open import issues, and the state of the search index.
 *
 * @phpstan-type JobRow array{public_id: string, kind: string, mode: string|null, status: string, source: string|null, version: string|null, release_ref: string|null, full: bool, bundle_files: array<int, string>, has_bundle: bool, checksum: string|null, progress: array<string, mixed>, report: array<string, mixed>|null, error: string|null, catalog_version_id: int|null, requested_by: string|null, queued_at: string|null, started_at: string|null, finished_at: string|null, created_at: string|null}
 */
final class CatalogImportsScreen
{
    public function __construct(private readonly CatalogCache $cache) {}

    /** @return JobRow */
    public function job(CatalogJob $job): array
    {
        return [
            'public_id' => $job->public_id,
            'kind' => $job->kind->value,
            'mode' => $job->mode,
            'status' => $job->status->value,
            'source' => $job->source,
            'version' => $job->version,
            'release_ref' => $job->release_ref,
            'full' => $job->full,
            'bundle_files' => $job->bundle_files,
            'has_bundle' => $job->bundle_path !== null && is_dir($job->bundle_path),
            'checksum' => $job->checksum,
            'progress' => (array) $job->progress,
            'report' => $job->report,
            'error' => $job->error,
            'catalog_version_id' => $job->catalog_version_id,
            'requested_by' => $job->requestedBy?->name,
            'queued_at' => $job->queued_at?->toIso8601String(),
            'started_at' => $job->started_at?->toIso8601String(),
            'finished_at' => $job->finished_at?->toIso8601String(),
            'created_at' => $job->created_at?->toIso8601String(),
        ];
    }

    /** @return list<JobRow> */
    public function jobs(int $limit = 30): array
    {
        return CatalogJob::query()->with('requestedBy')->orderByDesc('id')->limit($limit)->get()->map(fn (CatalogJob $j) => $this->job($j))->values()->all();
    }

    /** The latest job of a kind (the button's status line), or null. */
    /** @return JobRow|null */
    public function latest(CatalogJobKind $kind): ?array
    {
        $job = CatalogJob::query()->with('requestedBy')->ofKind($kind)->orderByDesc('id')->first();

        return $job === null ? null : $this->job($job);
    }

    /**
     * The release history, newest first, with each version's open issue count.
     *
     * @return list<array{id: int, version: string, status: string, dgda_release_ref: string|null, applied_at: string|null, applied_by: string|null, notes: string|null, row_counts: array<string, mixed>, checksum: string|null, issues_open: int, issues_total: int, is_current: bool}>
     */
    public function versions(int $limit = 30): array
    {
        $current = $this->cache->currentVersionRow();
        $currentId = $current === null ? null : (int) $current['id'];
        $issues = DB::connection('catalog')->table('catalog_import_issues')
            ->selectRaw('catalog_version_id, count(*) AS total, count(*) FILTER (WHERE resolved_at IS NULL) AS open')
            ->groupBy('catalog_version_id')->get()->keyBy('catalog_version_id');

        return CatalogVersion::query()->orderByDesc('id')->limit($limit)->get()->map(fn (CatalogVersion $v): array => [
            'id' => $v->id,
            'version' => $v->version,
            'status' => $v->status->value,
            'dgda_release_ref' => $v->dgda_release_ref,
            'applied_at' => $v->applied_at?->toIso8601String(),
            'applied_by' => $v->applied_by,
            'notes' => $v->notes,
            'row_counts' => (array) $v->row_counts,
            'checksum' => $v->checksum_sha256,
            'issues_open' => (int) ($issues[$v->id]->open ?? 0),
            'issues_total' => (int) ($issues[$v->id]->total ?? 0),
            'is_current' => $currentId === $v->id,
        ])->values()->all();
    }

    /**
     * Issues of one version (the result page's list).
     *
     * @return list<array{id: int, kind: string, source_row: int|null, payload: array<string, mixed>, resolved_at: string|null, resolution: array<string, mixed>|null}>
     */
    public function issues(int $versionId, int $limit = 200): array
    {
        return CatalogImportIssue::query()->where('catalog_version_id', $versionId)->orderBy('id')->limit($limit)->get()->map(fn (CatalogImportIssue $i): array => [
            'id' => $i->id,
            'kind' => $i->kind->value,
            'source_row' => $i->source_row,
            'payload' => (array) $i->payload,
            'resolved_at' => $i->resolved_at?->toIso8601String(),
            'resolution' => $i->resolution,
        ])->values()->all();
    }

    /** @return array{driver: string, reachable: bool, indexes: array<string, int|null>} */
    public function searchStatus(): array
    {
        $driver = (string) config('scout.driver');
        $status = ['driver' => $driver, 'reachable' => false, 'indexes' => []];

        if ($driver !== 'meilisearch') {
            return $status;
        }

        try {
            $client = app(Client::class);
            $indexer = app(CatalogSearchIndexer::class);
            $client->health();
            $status['reachable'] = true;

            foreach ([CatalogSearchIndexer::DRUGS, CatalogSearchIndexer::ICD10] as $index) {
                try {
                    $stats = $client->index($indexer->uid($index))->stats();
                    $status['indexes'][$indexer->uid($index)] = (int) ($stats['numberOfDocuments'] ?? 0);
                } catch (\Throwable) {
                    $status['indexes'][$indexer->uid($index)] = null;
                }
            }
        } catch (\Throwable) {
            // unreachable: the page says so and the rebuild button explains why it will fail
        }

        return $status;
    }

    /** @return array{open: int, by_kind: array<string, int>} */
    public function openIssues(): array
    {
        $rows = DB::connection('catalog')->table('catalog_import_issues')->whereNull('resolved_at')->selectRaw('kind, count(*) AS c')->groupBy('kind')->pluck('c', 'kind');
        $byKind = $rows->map(fn ($c) => (int) $c)->all();

        return ['open' => array_sum($byKind), 'by_kind' => $byKind];
    }
}
