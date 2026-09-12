<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Tenancy\Actions\ProvisionTenant;
use App\Domain\Tenancy\Data\ProvisionTenantData;
use App\Models\Central\Tenant;
use App\Tenancy\Facades\Tenancy;
use Database\Seeders\Tenant\AmzDemoSeeder;
use Illuminate\Database\Seeder;

/**
 * The AMZ Hospital reference clinic at `amz.{central domain}` — the pad the product owner photographed, as a
 * runnable tenant (BRIEF §5.A).
 *
 * Provisioning goes through `ProvisionTenant`, the same path as
 * `tenants:create "AMZ Hospital Ltd." --slug=amz --admin-email=admin@amz.test --admin-password=password`, so the
 * clinic has a real schema, real migrations, real roles and a real admin rather than rows a seeder invented. The
 * `--demo` flag is deliberately NOT passed: AMZ is one consultant's chamber, not the generic demo hospital.
 *
 * Re-runnable. On a machine where the tenant already exists it skips straight to `AmzDemoSeeder` inside that
 * tenant, which is itself idempotent — so this is also how you refresh the pad after editing the letterhead.
 */
final class AmzTenantSeeder extends Seeder
{
    public const SLUG = 'amz';

    public function run(ProvisionTenant $provision): void
    {
        $tenant = Tenant::withTrashed()->where('slug', self::SLUG)->first();

        if ($tenant === null) {
            $tenant = $provision->handle(new ProvisionTenantData(
                name: 'AMZ Hospital Ltd.',
                slug: self::SLUG,
                planCode: 'pro',
                ownerName: 'Dr. Md. Mostakim Billah',
                ownerEmail: 'owner@amz.test',
                ownerMobile: AmzDemoSeeder::HOTLINE,
                adminEmail: 'admin@amz.test',
                adminPassword: 'password',
                locale: 'en',
                adminName: 'AMZ Front Desk',
                branchName: 'AMZ Hospital Ltd.',
                branchCode: AmzDemoSeeder::BRANCH_CODE,
            ));

            $this->write("  amz tenant #{$tenant->id} provisioned ({$tenant->schema_name})");
        } else {
            $this->write("  amz tenant #{$tenant->id} already exists; re-seeding its clinic data");
        }

        Tenancy::run($tenant, fn () => (new AmzDemoSeeder)->run());

        $this->write('  amz clinic seeded — https://'.self::SLUG.'.'.config('tenancy.central_domain').'/panel (admin@amz.test / '.AmzDemoSeeder::DOCTOR_EMAIL.', password)');
    }

    private function write(string $message): void
    {
        $this->command->getOutput()->writeln($message);
    }
}
