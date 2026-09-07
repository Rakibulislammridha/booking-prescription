<?php

declare(strict_types=1);

namespace App\Tenancy\Console;

use App\Models\Central\Tenant;
use App\Tenancy\Database\TenantMigrator;
use App\Tenancy\Facades\Tenancy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Number;

final class TenantsListCommand extends Command
{
    protected $signature = 'tenants:list {--json : Output JSON}';

    protected $description = 'List tenants with schema, status, plan, domains, migration batch and schema size';

    public function handle(TenantMigrator $migrator): int
    {
        $rows = Tenant::query()
            ->with(['domains', 'currentSubscription.plan'])
            ->orderBy('id')
            ->get()
            ->map(function (Tenant $tenant) use ($migrator): array {
                $size = (int) DB::connection('pgsql')->scalar(
                    "select coalesce(sum(pg_total_relation_size(c.oid)), 0) from pg_class c join pg_namespace n on n.oid = c.relnamespace where n.nspname = ? and c.relkind in ('r', 'p', 'm')",
                    [$tenant->schema_name]
                );

                $schemaExists = (bool) DB::connection('pgsql')->scalar('select exists (select 1 from pg_namespace where nspname = ?)', [$tenant->schema_name]);
                $batch = $schemaExists ? Tenancy::run($tenant, fn () => $migrator->lastBatch()) : null;

                return [
                    'id' => $tenant->id,
                    'slug' => $tenant->slug,
                    'schema' => $tenant->schema_name,
                    'status' => $tenant->status->value,
                    'plan' => $tenant->currentSubscription?->plan?->code,
                    'domains' => $tenant->domains->pluck('domain')->implode(', '),
                    'batch' => $batch,
                    'size_bytes' => $size,
                ];
            });

        if ($this->option('json')) {
            $this->line((string) json_encode($rows->values()->all(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->table(
            ['id', 'slug', 'schema', 'status', 'plan', 'domains', 'batch', 'size'],
            $rows->map(fn (array $r) => [$r['id'], $r['slug'], $r['schema'], $r['status'], $r['plan'] ?? '-', $r['domains'], $r['batch'] ?? '-', Number::fileSize($r['size_bytes'])])->all()
        );

        return self::SUCCESS;
    }
}
