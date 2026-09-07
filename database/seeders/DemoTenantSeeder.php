<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Tenancy\Actions\ProvisionTenant;
use App\Domain\Tenancy\Data\ProvisionTenantData;
use App\Models\Central\Tenant;
use Illuminate\Database\Seeder;

/**
 * Provisions the `demo` tenant (schema, roles, branches, doctors, staff) once — the same path as
 * `tenants:create "Demo Hospital" --slug=demo --admin-email=admin@demo.test --admin-password=password --demo`.
 */
final class DemoTenantSeeder extends Seeder
{
    public function run(ProvisionTenant $provision): void
    {
        if (Tenant::withTrashed()->where('slug', 'demo')->exists()) {
            $this->command->getOutput()->writeln('  demo tenant already exists; skipping');

            return;
        }

        $tenant = $provision->handle(new ProvisionTenantData(
            name: 'Demo Hospital',
            slug: 'demo',
            planCode: 'pro',
            ownerName: 'ডা. আনোয়ার হোসেন',
            ownerEmail: 'admin@demo.test',
            ownerMobile: '+8801711000000',
            adminEmail: 'admin@demo.test',
            adminPassword: 'password',
            demo: true,
            adminName: 'হাসপাতাল অ্যাডমিন',
            branchName: 'ধানমন্ডি শাখা',
            branchCode: 'DHK',
        ));

        $this->command->getOutput()->writeln("  demo tenant #{$tenant->id} provisioned ({$tenant->schema_name})");
    }
}
