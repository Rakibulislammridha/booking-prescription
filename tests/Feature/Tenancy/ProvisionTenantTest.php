<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Domain\Clinic\Enums\Permission;
use App\Domain\Clinic\Enums\Role;
use App\Domain\Tenancy\Actions\ProvisionTenant;
use App\Domain\Tenancy\Data\ProvisionTenantData;
use App\Domain\Tenancy\Events\TenantProvisioned;
use App\Domain\Tenancy\Exceptions\PlanNotFound;
use App\Domain\Tenancy\Exceptions\SlugTaken;
use App\Models\Central\Tenant;
use App\Models\Tenant\Branch;
use App\Models\Tenant\Role as RoleModel;
use App\Models\Tenant\User;
use App\Tenancy\Facades\Tenancy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

final class ProvisionTenantTest extends TestCase
{
    public function test_tenants_create_provisions_schema_roles_branch_admin_and_domain(): void
    {
        $this->artisan('tenants:create', ['name' => 'Provision Clinic', '--slug' => 'prov-clinic', '--plan' => 'basic', '--admin-email' => 'admin@prov.test', '--admin-password' => 'secret123'])
            ->expectsOutputToContain('provisioned in schema')
            ->assertSuccessful();

        $tenant = Tenant::query()->where('slug', 'prov-clinic')->firstOrFail();

        $this->assertSame("tenant_{$tenant->id}", $tenant->schema_name);
        $this->assertSame('trial', $tenant->status->value);
        $this->assertNotNull($tenant->provisioned_at);
        $this->assertNotNull($tenant->current_subscription_id);
        $this->assertSame('basic', $tenant->currentSubscription?->plan?->code);
        $this->assertSame(26, strlen($tenant->public_id));
        $this->assertSame(['prov-clinic.bp.test'], $tenant->domains->pluck('domain')->all());
        $this->assertTrue((bool) DB::scalar('select exists (select 1 from pg_namespace where nspname = ?)', [$tenant->schema_name]));

        Tenancy::run($tenant, function () use ($tenant): void {
            $this->assertSame(count(Role::cases()), RoleModel::query()->count());
            $this->assertSame(count(Permission::cases()), DB::table('permissions')->count());

            $admin = User::query()->where('email', 'admin@prov.test')->firstOrFail();
            $this->assertTrue($admin->hasRole(Role::HospitalAdmin->value));
            $this->assertTrue($admin->can(Permission::ClinicUsersManage->value));
            $this->assertTrue(Hash::check('secret123', $admin->password));
            $this->assertSame($tenant->id, $admin->tenant_id);

            $branch = Branch::query()->where('is_main', true)->firstOrFail();
            $this->assertSame($branch->id, $admin->default_branch_id);
        });

        $this->assertFalse(Tenancy::check());
    }

    public function test_provisioning_dispatches_tenant_provisioned_after_commit(): void
    {
        Event::fake([TenantProvisioned::class]);

        $tenant = app(ProvisionTenant::class)->handle(new ProvisionTenantData(
            name: 'Evented', slug: 'evented', planCode: 'starter', ownerName: 'O', ownerEmail: 'o@e.test', ownerMobile: '+8801700000001', adminEmail: 'a@e.test', adminPassword: 'password',
        ));

        Event::assertDispatched(TenantProvisioned::class, fn (TenantProvisioned $e) => $e->tenant->is($tenant));
    }

    public function test_unknown_plan_and_taken_slug_are_domain_errors(): void
    {
        $this->assertThrows(fn () => app(ProvisionTenant::class)->handle(ProvisionTenantData::forTests(9500, 'nope', 'tenant_nope', planCode: 'missing')), PlanNotFound::class);
        $this->assertThrows(fn () => app(ProvisionTenant::class)->handle(ProvisionTenantData::forTests(9501, 'test-a', 'tenant_dup')), SlugTaken::class);

        $this->assertFalse(Tenant::query()->where('slug', 'nope')->exists());
        $this->assertFalse((bool) DB::scalar("select exists (select 1 from pg_namespace where nspname = 'tenant_nope')"));
    }

    public function test_tenants_list_shows_both_test_tenants(): void
    {
        $this->artisan('tenants:list')
            ->expectsOutputToContain('test-a')
            ->expectsOutputToContain(self::TENANT_B)
            ->assertSuccessful();
    }

    public function test_tenants_seed_reruns_roles_seeder_idempotently(): void
    {
        $this->artisan('tenants:seed', ['--tenant' => ['test-a'], '--class' => 'RolesAndPermissionsSeeder'])->assertSuccessful();

        $this->asTenant('a');
        $this->assertSame(count(Role::cases()), RoleModel::query()->count());
        $this->assertSame(count(Permission::cases()), DB::table('permissions')->count());
    }

    public function test_tenants_run_executes_a_command_inside_every_tenant(): void
    {
        $this->artisan('tenants:run', ['artisan' => 'tenants:seed', '--option' => ['class=RolesAndPermissionsSeeder']])->assertSuccessful();

        $this->assertFalse(Tenancy::check());
        $this->assertSame('public', DB::scalar('show search_path'));
    }
}
