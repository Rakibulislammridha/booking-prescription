<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Actions\Tenants;

use App\Domain\Audit\Enums\CentralAuditAction;
use App\Domain\SaaS\Enums\BackupStatus;
use App\Domain\SaaS\Enums\BackupType;
use App\Domain\SaaS\Events\TenantExportCompleted;
use App\Domain\SaaS\Services\CentralAudit;
use App\Domain\SaaS\Services\TenantExportArchive;
use App\Models\Central\Tenant;
use App\Models\Central\TenantBackup;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * The churn export (BRIEF §5.N). Registered in the same `tenant_backups` table as the dumps, with `type = export`,
 * so "what copies of this clinic's data exist and where" has exactly one answer.
 *
 * `tenants.data_export_requested_at` is stamped when the request is made, and the archive expires after 30 days —
 * long enough for a clinic to collect it, short enough that a full copy of someone's medical records is not
 * sitting in a bucket for ever.
 */
final class ExportTenantData
{
    public const RETENTION_DAYS = 30;

    public function __construct(
        private readonly TenantExportArchive $archive,
        private readonly CentralAudit $audit,
    ) {}

    public function handle(Tenant $tenant, ?int $superAdminId = null, string $disk = 'backups'): TenantBackup
    {
        $now = CarbonImmutable::now();

        $backup = TenantBackup::query()->create([
            'tenant_id' => $tenant->id,
            'type' => BackupType::Export,
            'status' => BackupStatus::Running,
            'storage_disk' => $disk,
            'started_at' => $now,
            'expires_at' => $now->addDays(self::RETENTION_DAYS),
            'requested_by_super_admin_id' => $superAdminId,
        ]);

        $tenant->forceFill(['data_export_requested_at' => $now])->save();

        try {
            $result = $this->archive->build($tenant, $disk);

            $backup->forceFill([
                'status' => BackupStatus::Completed,
                'storage_path' => $result['path'],
                'size_bytes' => $result['bytes'],
                'checksum_sha256' => $result['sha256'],
                'completed_at' => CarbonImmutable::now(),
            ])->save();
        } catch (Throwable $e) {
            $backup->forceFill([
                'status' => BackupStatus::Failed,
                'error' => mb_substr($e->getMessage(), 0, 2000),
                'completed_at' => CarbonImmutable::now(),
            ])->save();

            throw $e;
        }

        $this->audit->record(CentralAuditAction::Export, $tenant, $backup, null, ['path' => $backup->storage_path, 'bytes' => $backup->getAttribute('size_bytes')], $superAdminId);

        TenantExportCompleted::dispatch($tenant->id, $backup->id);

        return $backup;
    }
}
