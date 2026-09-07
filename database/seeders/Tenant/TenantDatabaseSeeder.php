<?php

declare(strict_types=1);

namespace Database\Seeders\Tenant;

use Illuminate\Database\Seeder;

/**
 * Runs inside Tenancy::run() (tenants:migrate --seed / tenants:seed). Idempotent.
 */
final class TenantDatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
        ]);
    }
}
