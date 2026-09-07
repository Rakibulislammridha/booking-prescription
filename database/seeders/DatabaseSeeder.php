<?php

declare(strict_types=1);

namespace Database\Seeders;

use Database\Seeders\Central\PlansSeeder;
use Database\Seeders\Central\SuperAdminSeeder;
use Illuminate\Database\Seeder;

/**
 * Central (public) seed for a dev box: plans, one super admin, and the demo tenant (idempotent).
 */
final class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            PlansSeeder::class,
            SuperAdminSeeder::class,
            DemoTenantSeeder::class,
        ]);
    }
}
