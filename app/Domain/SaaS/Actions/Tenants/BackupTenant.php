<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Actions\Tenants;

use App\Domain\SaaS\Enums\BackupStatus;
use App\Domain\SaaS\Enums\BackupType;
use App\Domain\SaaS\Services\TenantSchemaDumper;
use App\Models\Central\Tenant;
use App\Models\Central\TenantBackup;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * One `pg_dump -n tenant_<id>` → the `backups` disk → a `public.tenant_backups` row (SCHEMA §2.11).
 *
 * The row is written FIRST, as `running`, so a dump that crashes the process still leaves evidence that a backup
 * was attempted and did not complete — a backup registry that only records successes is a registry that lies.
 * Daily copies expire after 30 days (`expires_at`); manual ones are kept until a super admin removes them.
 */
final class BackupTenant
{
    public const DAILY_RETENTION_DAYS = 30;

    public function __construct(private readonly TenantSchemaDumper $dumper) {}

    public function handle(Tenant $tenant, BackupType $type = BackupType::Daily, ?int $superAdminId = null, string $disk = 'backups'): TenantBackup
    {
        $now = CarbonImmutable::now();

        $backup = TenantBackup::query()->create([
            'tenant_id' => $tenant->id,
            'type' => $type,
            'status' => BackupStatus::Running,
            'storage_disk' => $disk,
            'started_at' => $now,
            'expires_at' => $type === BackupType::Daily ? $now->addDays(self::DAILY_RETENTION_DAYS) : null,
            'requested_by_super_admin_id' => $superAdminId,
        ]);

        $local = tempnam(sys_get_temp_dir(), 'bp-backup-').'.dump';

        try {
            $dump = $this->dumper->dump($tenant, $local);
            $path = 'tenants/'.$tenant->id.'/'.$now->format('Ymd-His').'-'.$tenant->schema_name.'.dump';

            Storage::disk($disk)->put($path, (string) file_get_contents($dump['path']));

            $backup->forceFill([
                'status' => BackupStatus::Completed,
                'storage_path' => $path,
                'size_bytes' => $dump['bytes'],
                'checksum_sha256' => $dump['sha256'],
                'completed_at' => CarbonImmutable::now(),
            ])->save();

            $tenant->forceFill(['last_backup_at' => CarbonImmutable::now()])->save();
        } catch (Throwable $e) {
            $backup->forceFill([
                'status' => BackupStatus::Failed,
                'error' => mb_substr($e->getMessage(), 0, 2000),
                'completed_at' => CarbonImmutable::now(),
            ])->save();

            throw $e;
        } finally {
            @unlink($local);
        }

        return $backup;
    }
}
