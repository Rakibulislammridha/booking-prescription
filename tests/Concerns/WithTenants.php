<?php

declare(strict_types=1);

namespace Tests\Concerns;

use App\Domain\Clinic\Enums\Role;
use App\Domain\Clinic\Services\ActiveBranch;
use App\Domain\Tenancy\Actions\ProvisionTenant;
use App\Domain\Tenancy\Data\ProvisionTenantData;
use App\Http\Middleware\SetActiveBranch;
use App\Models\Central\SuperAdmin;
use App\Models\Central\Tenant;
use App\Models\Tenant\Branch;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\Patient;
use App\Models\Tenant\User;
use App\Tenancy\Facades\Tenancy;
use Closure;
use Database\Seeders\Central\PlansSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * CONVENTIONS §6.3. tenant_test_a / tenant_test_b (ids 9001 / 9002) are provisioned ONCE per process, committed;
 * switching tenants inside a test is just SET search_path (transaction-scoped, restored by the rollback).
 */
trait WithTenants
{
    public const TENANT_A = 'tenant_test_a';

    public const TENANT_B = 'tenant_test_b';

    /** Replaces RefreshDatabase::migrateDatabases(). Runs ONCE per process, fully committed. */
    protected function migrateDatabases(): void
    {
        $this->artisan('migrate:fresh', ['--seed' => false]);                     // public schema only (search path is 'public')
        $this->artisan('catalog:migrate', ['--fresh' => true, '--seed' => true]);  // catalog_test_N
        $this->seed(PlansSeeder::class);                                           // ProvisionTenant needs a plan row

        foreach ([self::TENANT_A, self::TENANT_B] as $schema) {
            DB::statement("drop schema if exists \"{$schema}\" cascade");
        }

        foreach ([[9001, 'test-a', self::TENANT_A], [9002, 'test-b', self::TENANT_B]] as [$id, $slug, $schema]) {
            app(ProvisionTenant::class)->handle(ProvisionTenantData::forTests(id: $id, slug: $slug, schemaName: $schema));   // migrations + roles + one branch + admin user
        }

        DB::statement("select setval(pg_get_serial_sequence('public.tenants', 'id'), 10000)");
    }

    public function setUpWithTenants(): void
    {
        Tenancy::check() && Tenancy::end();
    }

    public function tearDownWithTenants(): void
    {
        Tenancy::check() && Tenancy::end();
    }

    protected function tenant(string $which = 'a'): Tenant
    {
        return Tenant::query()->findOrFail($which === 'a' ? 9001 : 9002);
    }

    protected function asTenant(string $which = 'a'): static
    {
        Tenancy::check() && Tenancy::end();
        Tenancy::initialize($this->tenant($which));
        $this->withServerVariables(['HTTP_HOST' => "test-{$which}.bp.test"]);

        return $this;
    }

    protected function asCentral(): static
    {
        Tenancy::check() && Tenancy::end();
        $this->withServerVariables(['HTTP_HOST' => 'super.bp.test']);

        return $this;
    }

    /** Creates a user in the CURRENT tenant, assigns the role, logs in on guard 'web', sets the active branch. */
    protected function actingAsStaff(Role|string $role = Role::Receptionist, ?Branch $branch = null): User
    {
        $branch ??= Branch::query()->where('is_main', true)->first() ?? Branch::factory()->main()->create();

        $user = User::factory()->create(['default_branch_id' => $branch->id]);
        $user->assignRole($role instanceof Role ? $role->value : $role);

        $this->actingAs($user, 'web');
        $this->withSession([SetActiveBranch::SESSION_KEY => $branch->id]);
        app(ActiveBranch::class)->set($branch);

        return $user;
    }

    /**
     * Staff user with the doctor role + a Doctor row (profile + pad settings); schedules are the Scheduling module's.
     *
     * @param  array<string, mixed>  $profile
     */
    protected function actingAsDoctor(array $profile = []): User
    {
        $user = $this->actingAsStaff(Role::Doctor);

        $doctor = Doctor::factory()->complete()->create(['user_id' => $user->id, 'name' => $user->name]);

        if ($profile !== []) {
            $doctor->profile?->fill($profile)->save();
        }

        return $user;
    }

    /** Guard 'patient' (mobile-OTP identity, ARCHITECTURE §6.3) in the CURRENT tenant; created via the factory when null. */
    protected function actingAsPatient(?Patient $patient = null): Patient
    {
        $patient ??= Patient::factory()->create();

        $this->actingAs($patient, 'patient');

        return $patient;
    }

    /** Guard 'super' on the central host. */
    protected function actingAsSuper(): SuperAdmin
    {
        $this->asCentral();

        $admin = SuperAdmin::factory()->create();
        $this->actingAs($admin, 'super');

        return $admin;
    }

    /**
     * Isolation assertion: run $callback in tenant A, prove tenant B cannot see the rows it created.
     * Tenant B's row count is snapshotted first because provisioning seeds some tables (branches, users, roles).
     */
    protected function assertTenantIsolated(string $table, Closure $callback): void
    {
        $this->asTenant('b');
        $baselineB = DB::table($table)->count();

        $this->asTenant('a');
        $baselineA = DB::table($table)->count();
        $callback();
        $this->assertSame(self::TENANT_A, DB::scalar('show search_path'));
        $written = DB::table($table)->count() - $baselineA;
        $this->assertGreaterThan(0, $written, "callback wrote nothing to {$table}");

        $this->asTenant('b');
        $this->assertSame(self::TENANT_B, DB::scalar('show search_path'));
        $this->assertSame($baselineB, DB::table($table)->count(), "tenant_b can see {$written} rows of {$table} written by tenant_a");

        Tenancy::end();
        $this->assertSame('public', DB::scalar('show search_path'));

        // No public fallback: a bare tenant table name must not resolve. Run it in a nested transaction (savepoint)
        // so the failed statement does not abort the test's outer transaction.
        $this->assertThrows(fn () => DB::transaction(fn () => DB::table($table)->count()), QueryException::class);
    }

    /**
     * TRUNCATE … RESTART IDENTITY CASCADE inside Tenancy::run (concurrency tests clean up committed state).
     *
     * @param  array<int, string>  $tables
     */
    protected function truncateTenantTables(string $which, array $tables): void
    {
        Tenancy::run($this->tenant($which), function () use ($tables): void {
            foreach ($tables as $table) {
                DB::statement("truncate table \"{$table}\" restart identity cascade");
            }
        });
    }
}
