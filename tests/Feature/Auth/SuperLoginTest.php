<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\Central\AuditLogCentral;
use App\Models\Central\SuperAdmin;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

final class SuperLoginTest extends TestCase
{
    public function test_super_login_page_renders_on_the_central_host_only(): void
    {
        $this->asCentral();
        // assertInertia()->component() also proves the page file exists (config inertia.testing.ensure_pages_exist).
        $this->get('/login')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Super/Auth/Login'));

        $this->asTenant('a');
        $this->get('/login')->assertNotFound();
    }

    public function test_super_admin_can_log_in_and_the_login_is_recorded_centrally(): void
    {
        // The password half, on its own. `saas.two_factor.required` is off here so an operator with no second
        // factor can reach the console: the challenge, the forced enrolment and the lockout are SuperTwoFactorTest.
        config(['saas.two_factor.required' => false]);
        $this->asCentral();
        $admin = SuperAdmin::factory()->create(['email' => 'root@bp.test', 'password' => 'secret-123']);

        $this->post('/login', ['email' => 'root@bp.test', 'password' => 'secret-123'])->assertRedirect('http://super.bp.test');

        $this->assertAuthenticatedAs($admin, 'super');
        $this->assertSame(1, AuditLogCentral::query()->where('super_admin_id', $admin->id)->where('action', 'login')->count());

        $this->get('/')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Super/Dashboard')
                ->where('auth.guard', 'super')
                ->where('tenant', null)
                ->has('totals.tenants')
                ->has('attention')
                ->has('recent'));
    }

    public function test_super_guests_are_redirected_to_super_login_and_inactive_admins_are_rejected(): void
    {
        $this->asCentral();
        $this->get('/')->assertRedirect('http://super.bp.test/login');

        SuperAdmin::factory()->inactive()->create(['email' => 'off@bp.test', 'password' => 'secret-123']);
        $this->from('/login')->post('/login', ['email' => 'off@bp.test', 'password' => 'secret-123'])->assertSessionHasErrors('email');
        $this->assertGuest('super');
    }

    public function test_logged_in_super_admins_are_redirected_from_the_login_page_to_the_dashboard(): void
    {
        $this->actingAsSuper();

        $this->get('/login')->assertRedirect('http://super.bp.test');
    }

    public function test_super_logout(): void
    {
        $admin = $this->actingAsSuper();

        $this->post('/logout')->assertRedirect('http://super.bp.test/login');

        $this->assertGuest('super');
        $this->assertSame(1, AuditLogCentral::query()->where('super_admin_id', $admin->id)->where('action', 'logout')->count());
    }
}
