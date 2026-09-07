<?php

declare(strict_types=1);

namespace Tests\Feature\Tenancy\Adversarial;

use App\Models\Tenant\User;
use App\Tenancy\Facades\Tenancy;
use Illuminate\Auth\SessionGuard;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

/**
 * Attack surface 3 (cross-tenant reads by identity). users.id is per schema, so id 1 exists in every tenant (it is the
 * provisioned hospital admin). Sessions live in one shared store keyed only by session id, and the `web` guard loads
 * `login_web_*` by id inside whatever schema the request host activated.
 */
final class SessionReplayAcrossTenantsTest extends TestCase
{
    /**
     * EXPECTED TO FAIL until fixed: a staff session cookie minted on test-a.bp.test, replayed on test-b.bp.test,
     * authenticates as tenant B's user with the same id (tenant B's hospital admin). ARCHITECTURE §6.1 claims a session
     * id stolen across hosts "cannot load a user from another schema" — it loads *tenant B's* user instead.
     * Fix: pin the session to its tenant (write tenant_id into the session at login and reject/invalidate on mismatch
     * in a middleware after StartSession, and/or prefix the session store key with the tenant id).
     */
    public function test_a_session_from_tenant_a_cannot_authenticate_on_tenant_b(): void
    {
        $this->asTenant('a');
        $adminA = User::query()->where('email', 'admin@test-a.test')->firstOrFail();

        $login = $this->post('/panel/login', ['email' => 'admin@test-a.test', 'password' => 'password'])
            ->assertRedirect('http://test-a.bp.test/panel');
        $this->assertAuthenticatedAs($adminA, 'web');

        $sessionId = $login->getCookie((string) config('session.cookie'))?->getValue();
        $this->assertNotEmpty($sessionId);

        // Next request, fresh worker state (what Octane's FlushAuthenticationState + ResetTenancy do between requests).
        $this->app['auth']->forgetGuards();
        Tenancy::end();

        $response = $this->withServerVariables(['HTTP_HOST' => 'test-b.bp.test'])
            ->withCookie((string) config('session.cookie'), (string) $sessionId)
            ->withHeaders($this->inertiaHeaders())
            ->get('/panel');

        $user = Auth::guard('web')->user();
        $this->assertSame(9002, Tenancy::id());
        $this->assertNull($user, sprintf(
            'A tenant-A session cookie authenticated on tenant B as %s (users.id %s, tenant_id %s); HTTP %d.',
            $user?->email, $user?->id, $user?->tenant_id, $response->getStatusCode(),
        ));
        $response->assertRedirect('http://test-b.bp.test/panel/login');
    }

    /** Guarantee: the remember-me recaller is bound to users.remember_token, which is random per schema. */
    public function test_a_remember_me_cookie_from_tenant_a_is_rejected_on_tenant_b(): void
    {
        $this->asTenant('a');
        $adminA = User::query()->where('email', 'admin@test-a.test')->firstOrFail();

        $login = $this->post('/panel/login', ['email' => 'admin@test-a.test', 'password' => 'password', 'remember' => '1'])
            ->assertRedirect('http://test-a.bp.test/panel');

        $guard = Auth::guard('web');
        $this->assertInstanceOf(SessionGuard::class, $guard);
        $recallerName = $guard->getRecallerName();
        $recaller = (string) $login->getCookie($recallerName)?->getValue();
        $this->assertStringStartsWith($adminA->id.'|', $recaller);

        // Sanity: on its own host the recaller alone (no session) logs the user back in.
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->withCookie($recallerName, $recaller)->get('/panel')->assertOk();
        $this->assertAuthenticatedAs($adminA, 'web');

        // Replayed on tenant B it must not resolve tenant B's user #1.
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        Tenancy::end();

        $this->withServerVariables(['HTTP_HOST' => 'test-b.bp.test'])
            ->withCookie($recallerName, $recaller)
            ->get('/panel')
            ->assertRedirect('http://test-b.bp.test/panel/login');
        $this->assertGuest('web');
    }
}
