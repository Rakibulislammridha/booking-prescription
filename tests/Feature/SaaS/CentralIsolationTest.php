<?php

declare(strict_types=1);

namespace Tests\Feature\SaaS;

use App\Domain\Clinic\Enums\Role;
use App\Domain\SaaS\Actions\Tenants\ExportTenantData;
use App\Domain\SaaS\Enums\UsageMetric;
use App\Domain\SaaS\Queries\TenantOverview;
use App\Domain\SaaS\Services\PlanLimits;
use App\Domain\SaaS\Services\UsageMeter;
use App\Models\Central\Tenant;
use App\Models\Tenant\Doctor;
use App\Tenancy\Facades\Tenancy;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The control plane reaches into every clinic, which makes it the module most likely to leave a tenant search
 * path lying around or to answer one clinic's question with another's data. Same attack surface as
 * `tests/Feature/Tenancy/Adversarial/**`, aimed at this module.
 */
final class CentralIsolationTest extends TestCase
{
    /**
     * `WithTenants::asCentral()` only resets the search path when `Tenancy::check()` is true, so a session left on
     * a tenant schema by an earlier test (the context flushed, the Postgres session not) survives into this one.
     * These assertions are about what a SUPER REQUEST leaves behind, so the precondition is established here
     * rather than inherited: `Tenancy::end()` issues the explicit reset even with no tenant in the context.
     */
    protected function setUp(): void
    {
        parent::setUp();
        Tenancy::end();
    }

    public function test_every_saas_console_request_runs_with_no_tenancy_and_a_public_search_path(): void
    {
        $this->actingAsSuper();
        $tenant = $this->tenant('a');

        foreach ([
            route('super.dashboard', absolute: false),
            route('super.tenants.index', absolute: false),
            route('super.tenants.show', ['tenant' => $tenant->public_id], false),
            route('super.plans.index', absolute: false),
            route('super.usage.index', absolute: false),
            route('super.audit.index', absolute: false),
            route('super.catalog.reconciliation.index', absolute: false),
        ] as $url) {
            $this->get($url);
            $this->assertFalse(Tenancy::check(), "{$url} left a tenancy initialised");
            $this->assertSame('public', DB::scalar('show search_path'), "{$url} left the search path on a tenant schema");
        }
    }

    public function test_the_tenant_detail_reads_a_clinics_usage_without_entering_its_schema(): void
    {
        $tenantA = $this->tenant('a');
        $tenantB = $this->tenant('b');
        app(UsageMeter::class)->set($tenantA, UsageMetric::Doctors, 7);
        app(UsageMeter::class)->set($tenantB, UsageMetric::Doctors, 3);

        $detail = app(TenantOverview::class)->detail($tenantA);

        $this->assertFalse(Tenancy::check());
        $this->assertSame(7, $detail['counts']['doctors']);
        $this->assertSame(3, app(TenantOverview::class)->detail($tenantB)['counts']['doctors']);
    }

    public function test_actions_that_must_enter_a_schema_always_come_back_out(): void
    {
        Storage::fake('backups');
        Storage::fake('uploads');
        $tenant = $this->tenant('a');

        app(ExportTenantData::class)->handle($tenant);
        $this->assertFalse(Tenancy::check());
        $this->assertSame('public', DB::scalar('show search_path'));

        $this->artisan('saas:recount-usage', ['--tenant' => ['test-a']])->assertSuccessful();
        $this->assertFalse(Tenancy::check());
        $this->assertSame('public', DB::scalar('show search_path'));
    }

    public function test_the_control_plane_sweeps_refuse_to_run_inside_a_tenant(): void
    {
        $this->asTenant('a');

        foreach (['saas:renew-subscriptions', 'saas:dun', 'saas:recount-usage', 'saas:verify-domains', 'tenants:backup', 'tenants:export'] as $command) {
            $exit = $this->artisan($command, $command === 'tenants:export' ? ['tenant' => 'test-a'] : [])->run();
            $this->assertSame(1, $exit, "{$command} must refuse to run inside a tenant");
        }
    }

    public function test_usage_counters_written_from_inside_a_tenant_land_in_public_and_stay_per_tenant(): void
    {
        $tenantA = $this->tenant('a');
        $limits = app(PlanLimits::class);

        $this->asTenant('a');
        $limits->reserve($tenantA, UsageMetric::Prescriptions, 5);

        // The write happened on the public table even though the search path was the tenant's.
        $this->assertSame(self::TENANT_A, DB::scalar('show search_path'));
        $this->assertSame(5, (int) DB::table('public.usage_counters')
            ->where('tenant_id', $tenantA->id)->where('metric', 'prescriptions')->value('value'));

        // From inside the tenant the BARE name does not resolve: there is no public fallback on the tenant path,
        // which is exactly why every central table is addressed `public.…` in this module.
        $this->assertThrows(fn () => DB::transaction(fn () => DB::table('usage_counters')->count()), QueryException::class);

        Tenancy::end();
        $this->assertSame(5, (int) DB::table('public.usage_counters')
            ->where('tenant_id', $tenantA->id)->where('metric', 'prescriptions')->value('value'));
        $this->assertSame(0, (int) DB::table('public.usage_counters')
            ->where('tenant_id', $this->tenant('b')->id)->where('metric', 'prescriptions')->count());
    }

    public function test_central_tables_are_unreachable_from_a_tenant_search_path_without_qualification(): void
    {
        $this->asTenant('a');

        // The schema-qualified name works from anywhere; the bare one does not resolve (no public fallback).
        $this->assertGreaterThan(0, (int) DB::table('public.plans')->count());
        $this->assertThrows(fn () => DB::transaction(fn () => DB::table('plans')->count()), QueryException::class);
    }

    public function test_the_super_surface_is_not_reachable_on_a_tenant_host_and_the_panel_not_on_the_central_one(): void
    {
        $admin = $this->actingAsSuper();

        $this->withServerVariables(['HTTP_HOST' => 'test-a.bp.test']);
        $this->get('/tenants')->assertNotFound();

        $this->withServerVariables(['HTTP_HOST' => 'bp.test']);
        $this->get('/panel')->assertNotFound();
        $this->get('/panel/subscription')->assertNotFound();
    }

    public function test_a_tenant_staff_session_cannot_reach_the_super_console(): void
    {
        $this->asTenant('a');
        $this->actingAsStaff(Role::HospitalAdmin);

        $this->withServerVariables(['HTTP_HOST' => 'super.bp.test']);
        $this->get('/tenants')->assertRedirect(route('super.login', absolute: false));
    }

    public function test_saas_writes_are_isolated_per_tenant_schema(): void
    {
        $this->assertTenantIsolated('doctors', function (): void {
            Doctor::factory()->create();
        });
    }

    public function test_a_super_admin_reading_one_clinic_never_leaks_another(): void
    {
        $this->actingAsSuper();
        $a = $this->tenant('a');
        $b = $this->tenant('b');

        Tenancy::run($a, fn () => Doctor::factory()->create(['name' => 'DOCTOR-ONLY-IN-A']));

        $response = $this->get(route('super.tenants.show', ['tenant' => $b->public_id], false));

        $response->assertOk();
        $this->assertStringNotContainsString('DOCTOR-ONLY-IN-A', $response->getContent() ?: '');
        $this->assertFalse(Tenancy::check());
    }
}
