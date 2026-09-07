<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Clinic\Enums\Role;
use App\Domain\SaaS\Enums\TenantStatus;
use App\Models\Tenant\User;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

final class StaffLoginTest extends TestCase
{
    public function test_login_page_renders_the_panel_auth_page(): void
    {
        $this->asTenant('a');

        // assertInertia()->component() also proves the page file exists (config inertia.testing.ensure_pages_exist).
        $this->get('/panel/login')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Auth/Login')
                ->where('tenant.slug', 'test-a')
                ->where('auth.guard', null));
    }

    public function test_logged_in_staff_are_redirected_from_the_login_page_to_the_dashboard(): void
    {
        $this->asTenant('a');
        $this->actingAsStaff(Role::Receptionist);

        $this->get('/panel/login')->assertRedirect('http://test-a.bp.test/panel');
    }

    public function test_a_suspended_tenant_renders_the_suspended_page_with_402(): void
    {
        $this->tenant('a')->forceFill(['status' => TenantStatus::Suspended])->save();
        $this->asTenant('a');

        $this->get('/panel/login')
            ->assertStatus(402)
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Suspended')->where('tenant.name', 'Test Clinic A'));

        $this->get('/')
            ->assertStatus(402)
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Suspended'));
    }

    public function test_guests_are_redirected_to_the_panel_login(): void
    {
        $this->asTenant('a');

        $this->get('/panel')->assertRedirect('http://test-a.bp.test/panel/login');
    }

    public function test_staff_can_log_in_and_the_login_is_audited(): void
    {
        $this->asTenant('a');
        $user = User::factory()->withRole(Role::Receptionist)->create(['email' => 'desk@test-a.test', 'password' => 'secret-123']);

        $this->post('/panel/login', ['email' => 'desk@test-a.test', 'password' => 'secret-123'])
            ->assertRedirect('http://test-a.bp.test/panel');

        $this->assertAuthenticatedAs($user, 'web');
        $this->assertAudited(AuditAction::Login, $user, ['guard' => 'web']);
        $this->assertNotNull($user->fresh()?->last_login_at);
    }

    public function test_wrong_password_and_inactive_users_are_rejected(): void
    {
        $this->asTenant('a');
        User::factory()->create(['email' => 'desk@test-a.test', 'password' => 'secret-123']);
        User::factory()->inactive()->create(['email' => 'gone@test-a.test', 'password' => 'secret-123']);

        $this->from('/panel/login')->post('/panel/login', ['email' => 'desk@test-a.test', 'password' => 'nope'])
            ->assertRedirect('/panel/login')->assertSessionHasErrors('email');
        $this->from('/panel/login')->post('/panel/login', ['email' => 'gone@test-a.test', 'password' => 'secret-123'])
            ->assertRedirect('/panel/login')->assertSessionHasErrors('email');

        $this->assertGuest('web');
    }

    public function test_a_user_from_another_tenant_cannot_log_in_here(): void
    {
        $this->asTenant('b');
        User::factory()->create(['email' => 'b-only@test.test', 'password' => 'secret-123']);

        $this->asTenant('a');
        $this->from('/panel/login')->post('/panel/login', ['email' => 'b-only@test.test', 'password' => 'secret-123'])
            ->assertSessionHasErrors('email');

        $this->assertGuest('web');
    }

    public function test_logout_ends_the_session_and_is_audited(): void
    {
        $this->asTenant('a');
        $user = $this->actingAsStaff(Role::Accountant);

        $this->post('/panel/logout')->assertRedirect('http://test-a.bp.test/panel/login');

        $this->assertGuest('web');
        $this->assertAudited(AuditAction::Logout, $user);
    }

    public function test_dashboard_shares_the_props_contract(): void
    {
        $this->asTenant('a');
        $user = $this->actingAsStaff(Role::HospitalAdmin);

        $this->get('/panel')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('Dashboard/Index'));   // the page file exists
        $response = $this->withHeaders($this->inertiaHeaders())->get('/panel')->assertOk();

        $response->assertJsonPath('component', 'Dashboard/Index')
            ->assertJsonPath('props.auth.guard', 'web')
            ->assertJsonPath('props.auth.user.id', $user->id)
            ->assertJsonPath('props.auth.user.roles', ['hospital_admin'])
            ->assertJsonPath('props.auth.user.doctor_id', null)
            ->assertJsonPath('props.auth.impersonating', false)
            ->assertJsonPath('props.tenant.id', 9001)
            ->assertJsonPath('props.tenant.locale', 'bn')
            ->assertJsonPath('props.branch.id', $user->default_branch_id)
            ->assertJsonPath('props.locale', 'bn')
            ->assertJsonStructure(['props' => ['auth', 'tenant', 'branch', 'branches', 'locale', 'flash', 'features', 'ziggy' => ['url', 'routes'], 'csrf_token', 'app' => ['name', 'env', 'version', 'reverb'], 'errors']]);

        $this->assertContains('clinic.users.manage', $response->json('props.auth.user.permissions'));
        $this->assertArrayHasKey('panel.login', $response->json('props.ziggy.routes'));
        $this->assertArrayNotHasKey('super.login', $response->json('props.ziggy.routes'));
        $this->assertNotEmpty($response->json('props.branches'));
        $this->assertSame('http://test-a.bp.test', $response->json('props.ziggy.url'));
    }
}
