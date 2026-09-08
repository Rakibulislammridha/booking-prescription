<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Actions\Tenants;

use App\Domain\Audit\Enums\CentralAuditAction;
use App\Domain\SaaS\Enums\BackupStatus;
use App\Domain\SaaS\Services\BackupCipher;
use App\Domain\SaaS\Services\CentralAudit;
use App\Domain\SaaS\Services\TenantSchemaDumper;
use App\Models\Central\TenantBackup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Restore into a SCRATCH schema, verify, then swap (ARCHITECTURE §8.8).
 *
 * The live schema is never the restore target. The archive is rebuilt into `tenant_<id>_restore`, the schema is
 * checked for tables, and only then are the two schemas renamed past each other inside one transaction — so the
 * worst case of a bad archive is a scratch schema to drop, not a clinic with half its serials. The displaced
 * schema is kept as `tenant_<id>_replaced_<ts>` for a human to drop deliberately.
 *
 * READING THE OBJECT. It is streamed off the disk, never `get()`-ed into a string. Whether it is encrypted is
 * decided by the file's own magic prefix rather than by `tenant_backups.encryption`, so dumps written before
 * encryption existed still restore, and so a row whose column disagrees with its object cannot make the restore
 * silently wrong. `checksum_sha256` is verified against the PLAINTEXT archive (see `BackupTenant`) immediately
 * before `pg_restore` sees it — the last chance to notice that what came back off the disk is not what was taken
 * off the clinic.
 */
final class RestoreTenantBackup
{
    public function __construct(
        private readonly TenantSchemaDumper $dumper,
        private readonly BackupCipher $cipher,
        private readonly CentralAudit $audit,
    ) {}

    /** @return array{scratch: string, replaced: string, tables: int} */
    public function handle(TenantBackup $backup, ?int $superAdminId = null): array
    {
        if ($backup->status !== BackupStatus::Completed || $backup->storage_path === null) {
            throw new RuntimeException('backup '.$backup->id.' is not restorable');
        }

        $tenant = $backup->tenant;
        $object = tempnam(sys_get_temp_dir(), 'bp-restore-obj-');
        $archive = null;

        try {
            $this->download($backup, $object);

            if ($this->cipher->isEncrypted($object)) {
                $archive = tempnam(sys_get_temp_dir(), 'bp-restore-').'.dump';
                $this->cipher->decrypt($object, $archive);
            }

            $plaintext = $archive ?? $object;
            $this->verifyChecksum($backup, $plaintext);

            $scratch = $this->dumper->restoreIntoScratch($tenant, $plaintext);
            $tables = (int) DB::connection('pgsql')->scalar(
                "select count(*) from information_schema.tables where table_schema = ? and table_type = 'BASE TABLE'",
                [$scratch],
            );

            if ($tables === 0) {
                throw new RuntimeException('restore produced no tables in '.$scratch);
            }

            $replaced = $tenant->schema_name.'_replaced_'.now()->format('YmdHis');

            DB::connection('pgsql')->transaction(function () use ($tenant, $scratch, $replaced): void {
                DB::connection('pgsql')->statement('alter schema "'.$tenant->schema_name.'" rename to "'.$replaced.'"');
                DB::connection('pgsql')->statement('alter schema "'.$scratch.'" rename to "'.$tenant->schema_name.'"');
            });

            $this->audit->record(CentralAuditAction::Restore, $tenant, $backup, null, ['tables' => $tables, 'replaced_schema' => $replaced], $superAdminId);

            return ['scratch' => $scratch, 'replaced' => $replaced, 'tables' => $tables];
        } finally {
            @unlink($object);

            if ($archive !== null) {
                @unlink($archive);
            }
        }
    }

    private function download(TenantBackup $backup, string $destination): void
    {
        $remote = Storage::disk($backup->storage_disk)->readStream((string) $backup->storage_path);

        if ($remote === null) {
            throw new RuntimeException('backup object is missing from disk '.$backup->storage_disk);
        }

        $local = fopen($destination, 'wb');

        if ($local === false) {
            throw new RuntimeException('cannot stage the backup object at '.$destination);
        }

        try {
            if (stream_copy_to_stream($remote, $local) === false) {
                throw new RuntimeException('could not read the backup object off disk '.$backup->storage_disk);
            }
        } finally {
            fclose($local);
            fclose($remote);
        }
    }

    private function verifyChecksum(TenantBackup $backup, string $archive): void
    {
        $expected = $backup->getAttribute('checksum_sha256');

        if (! is_string($expected) || $expected === '') {
            return;
        }

        $actual = (string) hash_file('sha256', $archive);

        if (! hash_equals($expected, $actual)) {
            throw new RuntimeException("backup {$backup->id} does not match its recorded checksum: expected {$expected}, read {$actual}");
        }
    }
}
