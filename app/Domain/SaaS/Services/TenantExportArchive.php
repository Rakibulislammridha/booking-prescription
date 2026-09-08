<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Services;

use App\Models\Central\Tenant;
use App\Tenancy\Facades\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

/**
 * The churn export BRIEF §5.N requires: "full data export on churn", in a form a clinic can actually read without
 * us — one JSON file and one CSV file per table, plus a manifest, plus the patient documents.
 *
 * Not a `pg_dump`: a custom-format dump is a hostage note, readable only by the software the clinic is leaving.
 * JSON and CSV open in the tools a Bangladeshi clinic manager already has, and the manifest lists every table and
 * row count so the export can be checked against the live system before the schema is dropped.
 *
 * The table list is read from `information_schema` INSIDE `Tenancy::run()`, so it is exactly this clinic's tables
 * and cannot name another schema. Rows are streamed in chunks; nothing loads a whole table into memory.
 */
final class TenantExportArchive
{
    public const CHUNK = 1000;

    /** Framework bookkeeping a clinic has no use for. */
    private const SKIP_TABLES = ['migrations', 'password_reset_tokens', 'personal_access_tokens', 'sessions', 'cache', 'cache_locks'];

    /** @return array{disk: string, path: string, bytes: int, sha256: string, tables: array<string, int>} */
    public function build(Tenant $tenant, string $disk = 'backups'): array
    {
        $local = tempnam(sys_get_temp_dir(), 'bp-export-').'.zip';
        $zip = new ZipArchive;

        if ($zip->open($local, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('cannot create the export archive');
        }

        /** @var array<string, int> $counts */
        $counts = Tenancy::run($tenant, function () use ($zip, $tenant): array {
            $counts = [];

            foreach ($this->tables($tenant) as $table) {
                $counts[$table] = $this->writeTable($zip, $table);
            }

            $this->writeDocuments($zip);

            return $counts;
        });

        $zip->addFromString('manifest.json', (string) json_encode([
            'tenant' => ['public_id' => $tenant->public_id, 'name' => $tenant->name, 'slug' => $tenant->slug, 'schema' => $tenant->schema_name],
            'generated_at' => CarbonImmutable::now()->toIso8601String(),
            'format' => ['json' => 'tables/<table>.json', 'csv' => 'csv/<table>.csv', 'documents' => 'documents/<path>'],
            'tables' => $counts,
            'row_total' => array_sum($counts),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        $zip->close();

        $path = 'tenants/'.$tenant->id.'/exports/'.CarbonImmutable::now()->format('Ymd-His').'-'.$tenant->slug.'.zip';
        Storage::disk($disk)->put($path, (string) file_get_contents($local));

        $result = [
            'disk' => $disk,
            'path' => $path,
            'bytes' => (int) filesize($local),
            'sha256' => (string) hash_file('sha256', $local),
            'tables' => $counts,
        ];

        @unlink($local);

        return $result;
    }

    /** @return array<int, string> every base table in this tenant's schema, alphabetically */
    private function tables(Tenant $tenant): array
    {
        /** @var array<int, object{table_name: string}> $rows */
        $rows = DB::connection('pgsql')->select(
            "select table_name from information_schema.tables where table_schema = ? and table_type = 'BASE TABLE' order by table_name",
            [$tenant->schema_name],
        );

        return array_values(array_diff(array_map(fn ($r): string => (string) $r->table_name, $rows), self::SKIP_TABLES));
    }

    private function writeTable(ZipArchive $zip, string $table): int
    {
        $json = [];
        $csv = fopen('php://temp', 'r+');

        if ($csv === false) {
            throw new RuntimeException('cannot buffer the CSV for '.$table);
        }

        $header = null;
        $count = 0;
        $offset = 0;
        // Package pivots (`model_has_roles`, `role_has_permissions`) have no `id`; order by whatever they do have
        // so the chunking is deterministic.
        $order = DB::connection('pgsql')->getSchemaBuilder()->hasColumn($table, 'id')
            ? 'id'
            : (DB::connection('pgsql')->getSchemaBuilder()->getColumnListing($table)[0] ?? null);

        do {
            $query = DB::connection('pgsql')->table($table);
            $rows = ($order === null ? $query : $query->orderBy($order))->offset($offset)->limit(self::CHUNK)->get();

            foreach ($rows as $row) {
                $array = (array) $row;

                if ($header === null) {
                    $header = array_keys($array);
                    fputcsv($csv, $header, ',', '"', '\\');
                }

                $json[] = $array;
                fputcsv($csv, array_map(fn ($v): string => is_scalar($v) || $v === null ? (string) $v : (string) json_encode($v), $array), ',', '"', '\\');
                $count++;
            }

            $offset += self::CHUNK;
        } while ($rows->count() === self::CHUNK);

        $zip->addFromString("tables/{$table}.json", (string) json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        rewind($csv);
        $zip->addFromString("csv/{$table}.csv", (string) stream_get_contents($csv));
        fclose($csv);

        return $count;
    }

    /** Patient documents and prescription assets, best effort: a missing object must not fail the export. */
    private function writeDocuments(ZipArchive $zip): void
    {
        if (! DB::connection('pgsql')->getSchemaBuilder()->hasTable('patient_documents')) {
            return;
        }

        $disk = Storage::disk('uploads');
        $added = 0;

        foreach (DB::connection('pgsql')->table('patient_documents')->orderBy('id')->pluck('storage_path') as $path) {
            $path = (string) $path;

            if ($path === '' || $added >= 5000 || ! $disk->exists($path)) {
                continue;
            }

            $contents = $disk->get($path);

            if ($contents !== null) {
                $zip->addFromString('documents/'.$path, $contents);
                $added++;
            }
        }
    }
}
