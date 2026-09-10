<?php

declare(strict_types=1);

namespace Tests\Feature\SaaS;

use App\Domain\Audit\Enums\CentralAuditAction;
use App\Domain\Clinic\Enums\Role;
use App\Domain\Clinic\Services\StaffSessionIndex;
use App\Domain\SaaS\Jobs\SendStaffSetPasswordLinkJob;
use App\Models\Central\AuditLogCentral;
use App\Models\Central\ImpersonationToken;
use App\Models\Central\Tenant;
use App\Models\Tenant\Branch;
use App\Models\Tenant\User;
use App\Tenancy\Facades\Tenancy;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Support's hands inside a clinic's staff list (BRIEF §5.M): list, create an admin, hand out a way back in, switch
 * an account off, and sign in as one of them — every write through the clinic's own Clinic actions inside
 * `Tenancy::run()`, every one audited centrally, and never a tenant search path left behind.
 */
final class TenantStaffConsoleTest extends TestCase
{
    public function test_the_staff_tab_lists_the_clinics_users_read_inside_the_tenant_and_leaves_it(): void
    {
        $this->actingAsSuper();
        $tenant = $this->tenant('a');
        $receptionist = $this->staffIn($tenant, Role::Receptionist, ['name' => 'রিসেপশন ডেস্ক']);

        $this->get(route('super.tenants.show', ['tenant' => $tenant->public_id], false))->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('staff.0.role', Role::HospitalAdmin->value)
                ->where('staff', fn ($rows) => in_array($receptionist->public_id, self::publicIds($rows), true))
                ->has('roles')
                ->where('deletion.unsettled_invoices', 0));

        $this->assertFalse(Tenancy::check());
        $this->assertSame('public', DB::scalar('show search_path'));
    }

    public function test_creating_a_hospital_admin_with_a_temporary_password_shown_once(): void
    {
        $admin = $this->actingAsSuper();
        $tenant = $this->tenant('a');

        $this->post(route('super.tenants.staff.store', ['tenant' => $tenant->public_id], false), [
            'name' => 'নতুন অ্যাডমিন', 'email' => 'New.Admin@test-a.test', 'mobile' => '01911111111', 'role' => 'hospital_admin',
            'locale' => 'bn', 'credential' => 'password', 'password' => 'temp-pass-1234',
        ])->assertRedirect(route('super.tenants.show', ['tenant' => $tenant->public_id], false))->assertSessionHasNoErrors();

        $user = Tenancy::run($tenant, fn () => User::query()->where('email', 'new.admin@test-a.test')->firstOrFail());
        $this->assertTrue($user->must_change_password);
        $this->assertTrue(Hash::check('temp-pass-1234', $user->password));
        $this->assertSame('+8801911111111', $user->mobile);
        $this->assertContains(Role::HospitalAdmin->value, Tenancy::run($tenant, fn () => $user->getRoleNames()->all()));
        $this->assertNotNull($user->default_branch_id, 'the main branch is the default');

        $row = AuditLogCentral::query()->where('tenant_id', $tenant->id)->where('action', CentralAuditAction::Create->value)->where('super_admin_id', $admin->id)->latest('id')->firstOrFail();
        $this->assertSame('hospital_admin', $row->after['role'] ?? null);
        $this->assertSame($user->public_id, $row->after['user_public_id'] ?? null);
        $this->assertStringNotContainsString('temp-pass', json_encode($row->after));

        $this->get(route('super.tenants.show', ['tenant' => $tenant->public_id], false))->assertOk()
            ->assertInertia(fn ($page) => $page->where('reveal.kind', 'password')->where('reveal.value', 'temp-pass-1234')->where('reveal.email', 'new.admin@test-a.test'));
        $this->get(route('super.tenants.show', ['tenant' => $tenant->public_id], false))->assertOk()
            ->assertInertia(fn ($page) => $page->where('reveal', null));

        // A duplicate e-mail is a field error, discovered inside the clinic and mapped back onto the form.
        $this->post(route('super.tenants.staff.store', ['tenant' => $tenant->public_id], false), [
            'name' => 'Again', 'email' => 'new.admin@test-a.test', 'role' => 'doctor', 'credential' => 'link',
        ])->assertSessionHasErrors('email');

        $this->assertFalse(Tenancy::check());
        $this->assertSame('public', DB::scalar('show search_path'));
    }

    public function test_resetting_a_password_with_a_temporary_password_revokes_every_session_and_a_link_does_not(): void
    {
        Bus::fake([SendStaffSetPasswordLinkJob::class]);
        $admin = $this->actingAsSuper();
        $tenant = $this->tenant('a');
        $user = $this->staffIn($tenant, Role::Doctor);
        $index = app(StaffSessionIndex::class);
        Tenancy::run($tenant, fn () => $index->remember($user, 'sess-desk-1', '10.0.0.1', 'Desk'));
        $before = Tenancy::run($tenant, fn () => User::query()->findOrFail($user->id));
        $this->assertTrue(Tenancy::run($tenant, fn () => $index->has($user, 'sess-desk-1')));

        $this->post(route('super.tenants.staff.password', ['tenant' => $tenant->public_id, 'user' => $user->public_id], false), ['mode' => 'password'])
            ->assertRedirect(route('super.tenants.show', ['tenant' => $tenant->public_id], false))->assertSessionHasNoErrors();

        $reveal = (array) session()->get('super.tenants.reveal');
        $this->assertSame('password', $reveal['kind'] ?? null);
        $after = Tenancy::run($tenant, fn () => User::query()->findOrFail($user->id));
        $this->assertTrue(Hash::check((string) $reveal['value'], $after->password));
        $this->assertTrue($after->must_change_password);
        $this->assertNotSame($before->getRememberToken(), $after->getRememberToken(), 'the recaller cookie is dead too');
        $this->assertFalse(Tenancy::run($tenant, fn () => $index->has($user, 'sess-desk-1')), 'every live session is destroyed');

        $row = AuditLogCentral::query()->where('tenant_id', $tenant->id)->where('action', CentralAuditAction::Update->value)->where('super_admin_id', $admin->id)->latest('id')->firstOrFail();
        $this->assertSame('password', $row->after['password_reset'] ?? null);
        $this->assertTrue($row->after['sessions_revoked'] ?? false);
        $this->assertStringNotContainsString((string) $reveal['value'], json_encode($row->after));

        // The link mode: a real broker token on the clinic's host, e-mailed, sessions untouched.
        Tenancy::run($tenant, fn () => $index->remember($user, 'sess-desk-2', '10.0.0.2', 'Desk'));
        $this->post(route('super.tenants.staff.password', ['tenant' => $tenant->public_id, 'user' => $user->public_id], false), ['mode' => 'link'])
            ->assertRedirect()->assertSessionHasNoErrors();
        $reveal = (array) session()->get('super.tenants.reveal');
        $this->assertSame('link', $reveal['kind'] ?? null);
        $this->assertStringStartsWith('http://test-a.bp.test/panel/reset-password/', (string) $reveal['value']);
        Bus::assertDispatched(SendStaffSetPasswordLinkJob::class, fn (SendStaffSetPasswordLinkJob $job) => $job->address === $user->email && $job->tenantId === $tenant->id);
        $this->assertTrue(Tenancy::run($tenant, fn () => $index->has($user, 'sess-desk-2')));

        $this->post(route('super.tenants.staff.password', ['tenant' => $tenant->public_id, 'user' => $user->public_id], false), ['mode' => 'carrier-pigeon'])
            ->assertSessionHasErrors('mode');
    }

    public function test_deactivating_a_user_revokes_sessions_and_reactivating_lets_them_back_in(): void
    {
        $admin = $this->actingAsSuper();
        $tenant = $this->tenant('a');
        $user = $this->staffIn($tenant, Role::Receptionist);
        $index = app(StaffSessionIndex::class);
        Tenancy::run($tenant, fn () => $index->remember($user, 'sess-front', '10.0.0.3', 'Front desk'));
        $token = Tenancy::run($tenant, fn () => User::query()->findOrFail($user->id)->getRememberToken());
        $url = route('super.tenants.staff.status', ['tenant' => $tenant->public_id, 'user' => $user->public_id], false);

        $this->post($url, ['is_active' => false])->assertRedirect()->assertSessionHasNoErrors();

        $fresh = Tenancy::run($tenant, fn () => User::query()->findOrFail($user->id));
        $this->assertFalse($fresh->is_active);
        $this->assertNotSame($token, $fresh->getRememberToken());
        $this->assertFalse(Tenancy::run($tenant, fn () => $index->has($user, 'sess-front')));

        $row = AuditLogCentral::query()->where('tenant_id', $tenant->id)->where('action', CentralAuditAction::Update->value)->where('super_admin_id', $admin->id)->latest('id')->firstOrFail();
        $this->assertTrue($row->before['is_active'] ?? false);
        $this->assertFalse($row->after['is_active'] ?? true);
        $this->assertTrue($row->after['sessions_revoked'] ?? false);

        // Impersonating a deactivated account is refused; reactivating restores it.
        $this->post(route('super.tenants.impersonate', ['tenant' => $tenant->public_id], false), ['user' => $user->public_id])->assertSessionHasErrors('domain');

        $this->post($url, ['is_active' => true])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertTrue(Tenancy::run($tenant, fn () => User::query()->findOrFail($user->id))->is_active);
        $this->assertFalse(Tenancy::check());
        $this->assertSame('public', DB::scalar('show search_path'));
    }

    public function test_log_in_as_a_specific_user_goes_through_the_single_use_impersonation_handoff(): void
    {
        $admin = $this->actingAsSuper();
        $tenant = $this->tenant('a');
        $user = $this->staffIn($tenant, Role::Receptionist, ['name' => 'ফ্রন্ট ডেস্ক']);

        $response = $this->post(route('super.tenants.impersonate', ['tenant' => $tenant->public_id], false), ['user' => $user->public_id]);
        $response->assertRedirect();
        $location = (string) $response->headers->get('Location');
        $this->assertStringStartsWith('http://test-a.bp.test/panel/impersonate/', $location);

        $token = ImpersonationToken::query()->where('tenant_id', $tenant->id)->latest('id')->firstOrFail();
        $this->assertSame($user->id, $token->user_id, 'the public_id in the form is resolved to THIS clinic\'s user');
        $this->assertSame($admin->id, $token->super_admin_id);

        Auth::guard('super')->logout();
        $this->flushSession();
        $this->withServerVariables(['HTTP_HOST' => 'test-a.bp.test']);
        $this->get((string) parse_url($location, PHP_URL_PATH))->assertRedirect(route('panel.impersonate.started', absolute: false));
        $this->assertAuthenticatedAs(Tenancy::run($tenant, fn () => User::query()->findOrFail($user->id)), 'web');
        $this->assertTrue(AuditLogCentral::query()->where('tenant_id', $tenant->id)->where('action', CentralAuditAction::Impersonate->value)->where('after->user_id', $user->id)->exists());
    }

    public function test_staff_actions_are_scoped_to_the_clinic_in_the_url(): void
    {
        $this->actingAsSuper();
        $a = $this->tenant('a');
        $b = $this->tenant('b');
        $user = $this->staffIn($a, Role::Receptionist);

        // Tenant A's user, named under tenant B's URL: nobody of that id in B, nothing changes in A.
        $this->post(route('super.tenants.staff.status', ['tenant' => $b->public_id, 'user' => $user->public_id], false), ['is_active' => false])->assertSessionHasErrors('domain');
        $this->post(route('super.tenants.staff.password', ['tenant' => $b->public_id, 'user' => $user->public_id], false), ['mode' => 'password'])->assertSessionHasErrors('domain');
        $this->assertTrue(Tenancy::run($a, fn () => User::query()->findOrFail($user->id))->is_active);
        $this->assertSame(0, ImpersonationToken::query()->where('tenant_id', $b->id)->count());

        $this->post(route('super.tenants.impersonate', ['tenant' => $b->public_id], false), ['user' => $user->public_id])->assertSessionHasErrors('domain');
        $this->assertSame(0, ImpersonationToken::query()->where('tenant_id', $b->id)->count());

        $this->assertFalse(Tenancy::check());
        $this->assertSame('public', DB::scalar('show search_path'));
    }

    /**
     * The `staff` prop (a Collection under Inertia's `where`), reduced to public_ids.
     *
     * @return array<int, string>
     */
    private static function publicIds(mixed $rows): array
    {
        $out = [];

        foreach ($rows instanceof Collection ? $rows->all() : (is_array($rows) ? $rows : []) as $row) {
            $out[] = is_array($row) ? (string) ($row['public_id'] ?? '') : '';
        }

        return $out;
    }

    /** @param  array<string, mixed>  $attributes */
    private function staffIn(Tenant $tenant, Role $role, array $attributes = []): User
    {
        return Tenancy::run($tenant, function () use ($role, $attributes): User {
            $branch = Branch::query()->where('is_main', true)->firstOrFail();
            $user = User::factory()->create($attributes + ['default_branch_id' => $branch->id]);
            $user->assignRole($role->value);

            return $user;
        });
    }
}
