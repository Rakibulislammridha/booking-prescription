<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy;

use App\Models\Central\SuperAdmin;
use App\Models\Tenant\User;
use App\Tenancy\Http\Middleware\EnsureSessionBelongsToTenant;
use App\Tenancy\Http\Middleware\ResolveTenant;
use Illuminate\Auth\SessionGuard;
use Tests\TestCase;

/**
 * Layer (a) of the session-replay fix, exercised independently of the per-tenant cookie name (layer b):
 * a session carries `tenant_id`, written at login and on first use, and is invalidated when presented elsewhere.
 */
final class SessionTenantBindingTest extends TestCase
{
    public function test_login_binds_the_session_to_the_tenant_and_the_cookie_name_is_per_tenant(): void
    {
        $this->asTenant('a');

        $this->post('/panel/login', ['email' => 'admin@test-a.test', 'password' => 'password'])->assertRedirect('http://test-a.bp.test/panel');

        $this->assertSame(9001, session(EnsureSessionBelongsToTenant::SESSION_KEY));
        $this->assertSame('bp_test-a_session', config('session.cookie'));
        $this->assertSame('bp_test-a_session', app('session')->driver()->getName());
        $this->assertSame('bp_test-b_session', ResolveTenant::sessionCookieName($this->tenant('b')));
        $this->assertSame((string) config('tenancy.session_cookie_base'), ResolveTenant::sessionCookieName(null));
    }

    public function test_a_session_bound_to_tenant_a_is_invalidated_on_tenant_b_even_when_the_store_is_shared(): void
    {
        $this->asTenant('b');
        $adminB = User::query()->where('email', 'admin@test-b.test')->firstOrFail();

        // What a replayed cookie would load from the shared store: tenant A's marker for users.id = adminB->id.
        $this->withSession([EnsureSessionBelongsToTenant::SESSION_KEY => 9001, 'login_web_'.sha1(SessionGuard::class) => $adminB->id])
            ->get('/panel')
            ->assertRedirect('http://test-b.bp.test/panel/login');

        $this->assertGuest('web');
        $this->assertSame(9002, session(EnsureSessionBelongsToTenant::SESSION_KEY));
        $this->assertNull(session('login_web_'.sha1(SessionGuard::class)));
    }

    public function test_a_tenant_session_presented_on_the_central_host_is_invalidated(): void
    {
        $this->asCentral();
        $super = SuperAdmin::factory()->create(['email' => 'root@bp.test', 'password' => 'secret-123']);

        $this->withSession([EnsureSessionBelongsToTenant::SESSION_KEY => 9001, 'login_web_'.sha1(SessionGuard::class) => 1])
            ->post('/login', ['email' => 'root@bp.test', 'password' => 'secret-123'])
            ->assertRedirect('http://super.bp.test');

        $this->assertAuthenticatedAs($super, 'super');
        $this->assertNull(session(EnsureSessionBelongsToTenant::SESSION_KEY));
        $this->assertNull(session('login_web_'.sha1(SessionGuard::class)));
    }

    public function test_a_super_session_presented_on_a_tenant_host_is_invalidated(): void
    {
        $this->asTenant('a');

        $this->withSession(['login_super_'.sha1(SessionGuard::class) => 1])
            ->withHeaders($this->inertiaHeaders())
            ->get('/panel/login')
            ->assertOk();

        $this->assertNull(session('login_super_'.sha1(SessionGuard::class)));
        $this->assertSame(9001, session(EnsureSessionBelongsToTenant::SESSION_KEY));
    }
}
