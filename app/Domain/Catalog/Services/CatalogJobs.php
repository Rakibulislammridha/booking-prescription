<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Services;

use App\Domain\Audit\Enums\CentralAuditAction;
use App\Domain\Catalog\Enums\CatalogJobKind;
use App\Domain\Catalog\Enums\CatalogJobStatus;
use App\Domain\Catalog\Enums\ImportSource;
use App\Domain\Catalog\Exceptions\CatalogJobBusy;
use App\Domain\Catalog\Jobs\RebuildCatalogSearchIndex;
use App\Domain\Catalog\Jobs\RunCatalogImport;
use App\Domain\Catalog\Jobs\RunCatalogReconciliation;
use App\Domain\SaaS\Services\CentralAudit;
use App\Models\Central\CatalogJob;
use Carbon\CarbonImmutable;

/**
 * The console's side of catalog maintenance: every mutation of the shared catalog it asks for becomes a
 * `public.catalog_jobs` row and a Horizon job — never work inside the request (CATALOG.md §5: an import of
 * 60 k presentations takes minutes). One unfinished job per kind at a time: two imports interleaving would race
 * for the same `catalog_versions` "current" row, and a rebuild during an import would index half a release.
 */
final class CatalogJobs
{
    public function __construct(private readonly CentralAudit $audit) {}

    /**
     * Record an uploaded, validated bundle as a job waiting for a decision (dry run / apply).
     *
     * @param  array{path: string, files: list<string>, checksum: string, rows: array<string, int>}  $bundle
     */
    public function createImport(array $bundle, ImportSource $source, ?string $version, ?string $releaseRef, bool $full, int $superAdminId): CatalogJob
    {
        $job = CatalogJob::query()->create([
            'kind' => CatalogJobKind::Import,
            'mode' => null,
            'status' => CatalogJobStatus::Uploaded,
            'source' => $source->value,
            'version' => $version !== null && trim($version) !== '' ? trim($version) : null,
            'release_ref' => $releaseRef !== null && trim($releaseRef) !== '' ? trim($releaseRef) : null,
            'full' => $full,
            'bundle_path' => $bundle['path'],
            'bundle_files' => $bundle['files'],
            'checksum' => $bundle['checksum'],
            'progress' => ['rows' => $bundle['rows']],
            'requested_by_super_admin_id' => $superAdminId,
        ]);

        $this->audit->record(CentralAuditAction::Create, null, $job, null, [
            'kind' => 'import', 'files' => $bundle['files'], 'checksum' => $bundle['checksum'], 'source' => $source->value, 'full' => $full,
        ], $superAdminId);

        return $job;
    }

    /** Queue a dry run or the real thing for an uploaded bundle (a finished job may be re-queued: dry run → apply). */
    public function queueImport(CatalogJob $job, bool $dryRun, int $superAdminId, bool $force = false): CatalogJob
    {
        $this->assertIdle(CatalogJobKind::Import, $job);

        if ($job->bundle_path === null || ! is_dir($job->bundle_path)) {
            throw new CatalogJobBusy('The uploaded bundle is no longer on disk; upload it again.');
        }

        $job->forceFill([
            'mode' => $dryRun ? 'dry_run' : 'apply',
            'status' => CatalogJobStatus::Queued,
            'progress' => ['rows' => $job->progress['rows'] ?? [], 'force' => $force],
            'report' => null,
            'error' => null,
            'queued_at' => CarbonImmutable::now(),
            'started_at' => null,
            'finished_at' => null,
            'requested_by_super_admin_id' => $superAdminId,
        ])->save();

        $this->audit->record(CentralAuditAction::Update, null, $job, null, ['queued' => $dryRun ? 'dry_run' : 'apply', 'version' => $job->version, 'force' => $force], $superAdminId);

        RunCatalogImport::dispatch($job->id)->afterCommit();

        return $job;
    }

    public function queueReindex(int $superAdminId): CatalogJob
    {
        $this->assertIdle(CatalogJobKind::Reindex);

        $job = $this->createMaintenance(CatalogJobKind::Reindex, $superAdminId);
        RebuildCatalogSearchIndex::dispatch($job->id)->afterCommit();

        return $job;
    }

    public function queueReconcile(int $superAdminId): CatalogJob
    {
        $this->assertIdle(CatalogJobKind::Reconcile);

        $job = $this->createMaintenance(CatalogJobKind::Reconcile, $superAdminId);
        RunCatalogReconciliation::dispatch($job->id)->afterCommit();

        return $job;
    }

    /** The job the workers see as running or waiting for this kind, if any. */
    public function unfinished(CatalogJobKind $kind): ?CatalogJob
    {
        return CatalogJob::query()->ofKind($kind)->unfinished()->orderByDesc('id')->first();
    }

    // Progress and outcome, written by the jobs themselves.

    /** @param  array<string, mixed>  $progress */
    public function start(CatalogJob $job, array $progress = []): void
    {
        $job->forceFill(['status' => CatalogJobStatus::Running, 'started_at' => CarbonImmutable::now(), 'progress' => $progress + (array) $job->progress])->save();
    }

    /** @param  array<string, mixed>  $progress */
    public function progress(CatalogJob $job, array $progress): void
    {
        $job->forceFill(['progress' => $progress + (array) $job->progress])->save();
    }

    /** @param  array<string, mixed>|null  $report */
    public function succeed(CatalogJob $job, ?array $report, ?int $catalogVersionId = null): void
    {
        $job->forceFill([
            'status' => CatalogJobStatus::Succeeded,
            'report' => $report,
            'catalog_version_id' => $catalogVersionId ?? $job->catalog_version_id,
            'finished_at' => CarbonImmutable::now(),
            'progress' => ['percent' => 100] + (array) $job->progress,
        ])->save();
    }

    public function fail(CatalogJob $job, string $error, ?string $code = null): void
    {
        $job->forceFill([
            'status' => CatalogJobStatus::Failed,
            'error' => mb_substr($error, 0, 4000),
            'finished_at' => CarbonImmutable::now(),
            'progress' => ['code' => $code] + (array) $job->progress,
        ])->save();
    }

    private function createMaintenance(CatalogJobKind $kind, int $superAdminId): CatalogJob
    {
        $job = CatalogJob::query()->create([
            'kind' => $kind,
            'status' => CatalogJobStatus::Queued,
            'queued_at' => CarbonImmutable::now(),
            'requested_by_super_admin_id' => $superAdminId,
        ]);

        $this->audit->record(CentralAuditAction::Create, null, $job, null, ['kind' => $kind->value, 'queued' => true], $superAdminId);

        return $job;
    }

    private function assertIdle(CatalogJobKind $kind, ?CatalogJob $except = null): void
    {
        $busy = $this->unfinished($kind);

        if ($busy !== null && ($except === null || $busy->id !== $except->id)) {
            throw new CatalogJobBusy("A {$kind->value} job is already {$busy->status->value} (#{$busy->public_id}).");
        }
    }
}
