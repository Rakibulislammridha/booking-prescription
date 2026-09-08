<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Actions\Tenants;

use App\Domain\Audit\Enums\CentralAuditAction;
use App\Domain\SaaS\Enums\BackupStatus;
use App\Domain\SaaS\Services\CentralAudit;
use App\Domain\SaaS\Services\TenantSchemaDumper;
use App\Models\Central\TenantBackup;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Restore into a SCRATCH schema, verify, then swap (ARCHITECTURE §8.8).
 *
 * The live schema is never the restore target. `pg_restore` writes into `tenant_<id>_restore`, the row counts are
 * compared against the manifest of the dump, and only then are the two schemas renamed past each other inside one
 * transaction — so the worst case of a bad archive is a scratch schema to drop, not a clinic with half its
 * serials. The displaced schema is kept as `tenant_<id>_replaced_<ts>` for a human to drop deliberately.
 */
final class RestoreTenantBackup
{
    public function __construct(
        private readonly TenantSchemaDumper $dumper,
        private readonly CentralAudit $audit,
    ) {}

    /** @return array{scratch: string, replaced: string, tables: int} */
    public function handle(TenantBackup $backup, ?int $superAdminId = null): array
    {
        if ($backup->status !== BackupStatus::Completed || $backup->storage_path === null) {
            throw new RuntimeException('backup '.$backup->id.' is not restorable');
        }

        $tenant = $backup->tenant;
        $contents = Storage::disk($backup->storage_disk)->get($backup->storage_path);

        if ($contents === null) {
            throw new RuntimeException('backup object is missing from disk '.$backup->storage_disk);
        }

        $local = tempnam(sys_get_temp_dir(), 'bp-restore-').'.dump';
        file_put_contents($local, $contents);

        try {
            $scratch = $this->dumper->restoreIntoScratch($tenant, $local);
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
            @unlink($local);
        }
    }
}
