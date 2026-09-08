<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Actions\Tenants;

use App\Domain\SaaS\Enums\BackupEncryption;
use App\Domain\SaaS\Enums\BackupStatus;
use App\Domain\SaaS\Enums\BackupType;
use App\Domain\SaaS\Services\BackupCipher;
use App\Domain\SaaS\Services\TenantSchemaDumper;
use App\Models\Central\Tenant;
use App\Models\Central\TenantBackup;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * One `pg_dump -n tenant_<id>` → `BackupCipher` → the `backups` disk → a `public.tenant_backups` row (SCHEMA §2.11).
 *
 * The row is written FIRST, as `running`, so a dump that crashes the process still leaves evidence that a backup
 * was attempted and did not complete — a backup registry that only records successes is a registry that lies.
 * Daily copies expire after 30 days (`expires_at`); manual ones are kept until a super admin removes them.
 *
 * The encryption mode is resolved BEFORE the dump runs: a production host with no `BP_BACKUP_KEY` should fail in
 * the first millisecond with `status = failed` and a legible `error`, not ten minutes later holding a plaintext
 * archive it is not allowed to upload.
 *
 * WHAT THE TWO SIZE/CHECKSUM COLUMNS MEAN, because they are no longer the same file:
 *   • `size_bytes` is the size of the OBJECT at `storage_path` — the thing that occupies the bucket and that a
 *     download transfers, so it is the number an operator can check against the disk.
 *   • `checksum_sha256` is the digest of the PLAINTEXT archive — the bytes `pg_restore` is going to eat. It stays
 *     comparable with every row written before encryption existed (all of which were plaintext), and it is what
 *     `RestoreTenantBackup` verifies AFTER decrypting. Digesting the ciphertext instead would add nothing: the
 *     secretstream's Poly1305 tags already authenticate every chunk and its position, which a SHA-256 of the same
 *     bytes cannot improve on, while the question "is the archive I am about to restore the one taken from the
 *     clinic" would go unanswered.
 * Nothing is ever read whole: the dump is streamed through the cipher and streamed onto the disk.
 */
final class BackupTenant
{
    public const DAILY_RETENTION_DAYS = 30;

    public function __construct(
        private readonly TenantSchemaDumper $dumper,
        private readonly BackupCipher $cipher,
    ) {}

    public function handle(Tenant $tenant, BackupType $type = BackupType::Daily, ?int $superAdminId = null, string $disk = 'backups'): TenantBackup
    {
        $now = CarbonImmutable::now();

        $backup = TenantBackup::query()->create([
            'tenant_id' => $tenant->id,
            'type' => $type,
            'status' => BackupStatus::Running,
            'storage_disk' => $disk,
            'encryption' => BackupEncryption::None,
            'started_at' => $now,
            'expires_at' => $type === BackupType::Daily ? $now->addDays(self::DAILY_RETENTION_DAYS) : null,
            'requested_by_super_admin_id' => $superAdminId,
        ]);

        $local = tempnam(sys_get_temp_dir(), 'bp-backup-').'.dump';
        $encrypted = null;

        try {
            $mode = $this->cipher->resolveMode();
            $dump = $this->dumper->dump($tenant, $local);
            $object = $local;

            if ($mode === BackupEncryption::XChaCha20Poly1305) {
                $encrypted = tempnam(sys_get_temp_dir(), 'bp-backup-').'.dump.enc';
                $this->cipher->encrypt($local, $encrypted);
                $object = $encrypted;
            }

            $path = 'tenants/'.$tenant->id.'/'.$now->format('Ymd-His').'-'.$tenant->schema_name.'.dump'.$mode->fileSuffix();

            $this->upload($disk, $path, $object);

            $backup->forceFill([
                'status' => BackupStatus::Completed,
                'storage_path' => $path,
                'encryption' => $mode,
                'size_bytes' => (int) filesize($object),
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

            if ($encrypted !== null) {
                @unlink($encrypted);
            }
        }

        return $backup;
    }

    /** `writeStream`, not `put(file_get_contents(...))`: a clinic's dump does not have to fit in a worker's memory. */
    private function upload(string $disk, string $path, string $source): void
    {
        $handle = fopen($source, 'rb');

        if ($handle === false) {
            throw new RuntimeException("Cannot read the finished dump at {$source}.");
        }

        try {
            if (Storage::disk($disk)->writeStream($path, $handle) === false) {
                throw new RuntimeException("Could not write {$path} to the {$disk} disk.");
            }
        } finally {
            fclose($handle);
        }
    }
}
