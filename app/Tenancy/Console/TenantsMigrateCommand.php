<?php

declare(strict_types=1);

namespace App\Tenancy\Console;

use App\Models\Central\Tenant;
use App\Tenancy\Database\TenantMigrator;
use App\Tenancy\Facades\Tenancy;
use Database\Seeders\Tenant\TenantDatabaseSeeder;
use Illuminate\Console\Command;
use Throwable;

final class TenantsMigrateCommand extends Command
{
    use ResolvesTenants;

    protected $signature = 'tenants:migrate {--tenant=* : ids or slugs (default: every servable tenant)}
        {--fresh : Drop and recreate the schema first}
        {--seed : Run Database\Seeders\Tenant\TenantDatabaseSeeder afterwards}
        {--step : Record each migration in its own batch}
        {--pretend : Dump the SQL instead of running it}';

    protected $description = 'Run tenant-schema migrations (database/migrations/tenant) for one, several or all tenants';

    public function handle(TenantMigrator $migrator): int
    {
        $failed = 0;

        foreach ($this->resolveTenants(includeAllStatuses: (bool) $this->option('tenant')) as $tenant) {
            $this->components->info("Tenant #{$tenant->id} [{$tenant->slug}] → {$tenant->schema_name}");

            try {
                $this->migrateTenant($tenant, $migrator);
            } catch (Throwable $e) {
                $failed++;
                $this->components->error("Tenant #{$tenant->id} failed: {$e->getMessage()}");
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function migrateTenant(Tenant $tenant, TenantMigrator $migrator): void
    {
        if ($this->option('fresh')) {
            if (Tenancy::check()) {
                Tenancy::end();
            }

            $migrator->recreateSchema($tenant);
        } else {
            $migrator->ensureSchema($tenant);
        }

        Tenancy::run($tenant, function () use ($migrator): void {
            $ran = $migrator->migrate(['step' => (bool) $this->option('step'), 'pretend' => (bool) $this->option('pretend')], $this->output);

            if ($ran === []) {
                $this->components->twoColumnDetail('Migrations', 'nothing to migrate');
            }

            if ($this->option('seed') && ! $this->option('pretend')) {
                $this->laravel->make(TenantDatabaseSeeder::class)->setContainer($this->laravel)->setCommand($this)->__invoke();
            }
        });
    }
}
