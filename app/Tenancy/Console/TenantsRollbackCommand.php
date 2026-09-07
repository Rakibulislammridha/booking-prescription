<?php

declare(strict_types=1);

namespace App\Tenancy\Console;

use App\Tenancy\Database\TenantMigrator;
use App\Tenancy\Facades\Tenancy;
use Illuminate\Console\Command;
use Throwable;

final class TenantsRollbackCommand extends Command
{
    use ResolvesTenants;

    protected $signature = 'tenants:rollback {--tenant=* : ids or slugs} {--step=1 : Number of migrations to roll back (Laravel semantics: the last N migration files, not batches)} {--pretend}';

    protected $description = 'Roll back the last N tenant-schema migrations for one, several or all tenants';

    public function handle(TenantMigrator $migrator): int
    {
        $failed = 0;

        foreach ($this->resolveTenants(includeAllStatuses: (bool) $this->option('tenant')) as $tenant) {
            $this->components->info("Tenant #{$tenant->id} [{$tenant->slug}] → {$tenant->schema_name}");

            try {
                Tenancy::run($tenant, fn () => $migrator->rollback(['step' => (int) $this->option('step'), 'pretend' => (bool) $this->option('pretend')], $this->output));
            } catch (Throwable $e) {
                $failed++;
                $this->components->error("Tenant #{$tenant->id} failed: {$e->getMessage()}");
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
