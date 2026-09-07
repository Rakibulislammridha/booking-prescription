<?php

declare(strict_types=1);

namespace App\Tenancy\Console;

use App\Tenancy\Facades\Tenancy;
use Illuminate\Console\Command;
use Illuminate\Database\Seeder;
use InvalidArgumentException;
use Throwable;

final class TenantsSeedCommand extends Command
{
    use ResolvesTenants;

    protected $signature = 'tenants:seed {--tenant=* : ids or slugs} {--class=TenantDatabaseSeeder : Class in Database\Seeders\Tenant (or FQCN)}';

    protected $description = 'Run a tenant seeder for one, several or all tenants (RolesAndPermissionsSeeder on every deploy)';

    public function handle(): int
    {
        $class = (string) $this->option('class');
        $class = str_contains($class, '\\') ? $class : 'Database\\Seeders\\Tenant\\'.$class;

        if (! class_exists($class) || ! is_subclass_of($class, Seeder::class)) {
            throw new InvalidArgumentException("Seeder [{$class}] does not exist.");
        }

        $failed = 0;

        foreach ($this->resolveTenants(includeAllStatuses: (bool) $this->option('tenant')) as $tenant) {
            $this->components->info("Tenant #{$tenant->id} [{$tenant->slug}] ← {$class}");

            try {
                Tenancy::run($tenant, function () use ($class): void {
                    /** @var Seeder $seeder */
                    $seeder = $this->laravel->make($class);
                    $seeder->setContainer($this->laravel)->setCommand($this)->__invoke();
                });
            } catch (Throwable $e) {
                $failed++;
                $this->components->error("Tenant #{$tenant->id} failed: {$e->getMessage()}");
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
