<?php

declare(strict_types=1);

namespace Tests\Feature\Clinic;

use App\Domain\Clinic\Actions\UpdateStaffUser;
use App\Domain\Clinic\Data\StaffUserData;
use App\Domain\Clinic\Enums\Role;
use App\Domain\Clinic\Services\StaffSessionIndex;
use App\Domain\Shared\Actor;
use App\Models\Tenant\Branch;
use App\Models\Tenant\User;
use Illuminate\Auth\SessionGuard;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Session;
use Tests\TestCase;

/**
 * B1 regression (was tests/Feature/Audit2/RememberMeRevocationTest). "Remember me" used to defeat both device
 * revocation and account deactivation: the recaller cookie re-authenticated through a path that never re-checked
 * `is_active` or the rotated session. Now revoking a device — and deactivating an account — rotates the user's
 * remember_token (StaffSessionIndex), so every recaller cookie the account holds is dead, and EnsureStaffIsActive
 * catches a live session whose account was deactivated mid-flight.
 */
final class RememberMeRevocationTest extends TestCase
{
    private const PASSWORD = 'desk-password-123';

    protected function tearDown(): void
    {
        // The device index lives in Redis, which no database transaction rolls back.
        Redis::connection()->del(...array_map(fn (int $id) => "bp:sessions_by_user:9001:{$id}", range(1, 80)));
        parent::tearDown();
    }

    public function test_revoking_a_device_kills_its_remember_me_cookie(): void
    {
        [$user, $sessionId, $recallerName, $recaller] = $this->rememberedLogin();

        $index = app(StaffSessionIndex::class);
        $this->assertTrue($index->has($user, $sessionId), 'precondition: the device is in the index');
        $this->assertTrue($index->revoke($user, $sessionId));
        $this->assertSame('', Session::getHandler()->read($sessionId), 'the session payload is gone');

        // The dead session cookie alone is a guest (control) …
        $this->freshBrowserState();
        $this->withCookie((string) config('session.cookie'), $sessionId)
            ->get('/panel')->assertRedirect(route('panel.login', absolute: false));
        $this->assertGuest('web');

        // … and now so is the recaller the login handed out: rotating remember_token killed it.
        $this->freshBrowserState();
        $this->withCookie($recallerName, $recaller)
            ->get('/panel')->assertRedirect(route('panel.login', absolute: false));
        $this->assertGuest('web');
    }

    public function test_deactivating_an_account_kills_its_remember_me_cookie(): void
    {
        [$user, , $recallerName, $recaller] = $this->rememberedLogin();

        $admin = User::factory()->create(['default_branch_id' => $user->default_branch_id]);
        $admin->assignRole(Role::HospitalAdmin->value);

        app(UpdateStaffUser::class)->handle($user, new StaffUserData(
            name: $user->name, email: $user->email, role: Role::Receptionist, mobile: null,
            defaultBranchId: $user->default_branch_id, locale: 'bn', isActive: false,
        ), Actor::user($admin->id));

        $this->freshBrowserState();
        $this->withCookie($recallerName, $recaller)
            ->get('/panel')->assertRedirect(route('panel.login', absolute: false));
        $this->assertGuest('web');
    }

    public function test_a_live_session_is_thrown_out_when_the_account_is_deactivated_mid_flight(): void
    {
        $this->asTenant('a');
        $user = $this->actingAsStaff(Role::Receptionist);
        $sessionId = $this->pinBrowserSession();

        // Deactivate WITHOUT going through the action that destroys sessions, so the live session payload survives:
        // this proves EnsureStaffIsActive re-checks is_active on every request rather than trusting the session.
        $user->forceFill(['is_active' => false])->saveQuietly();

        $this->withCookie((string) config('session.cookie'), $sessionId)
            ->get('/panel')
            ->assertRedirect(route('panel.login', absolute: false))
            ->assertSessionHas('flash.error', __('auth.account_inactive'));
        $this->assertGuest('web');
    }

    public function test_a_deactivated_account_gets_401_on_an_xhr_rather_than_a_redirect(): void
    {
        $this->asTenant('a');
        $user = $this->actingAsStaff(Role::Receptionist);
        $sessionId = $this->pinBrowserSession();
        $user->forceFill(['is_active' => false])->saveQuietly();

        $this->withCookie((string) config('session.cookie'), $sessionId)
            ->getJson('/panel')
            ->assertStatus(401)
            ->assertJsonPath('message', __('auth.account_inactive'));
    }

    /** @return array{0: User, 1: string, 2: string, 3: string} */
    private function rememberedLogin(): array
    {
        $this->asTenant('a');
        $branch = Branch::query()->where('is_main', true)->firstOrFail();
        $user = User::factory()->create(['password' => self::PASSWORD, 'default_branch_id' => $branch->id, 'is_active' => true]);
        $user->assignRole(Role::Receptionist->value);

        $response = $this->post('/panel/login', ['email' => $user->email, 'password' => self::PASSWORD, 'remember' => 1]);
        $response->assertRedirect();
        $this->assertAuthenticatedAs($user, 'web');

        $recallerName = $this->recallerName();
        $cookie = $response->getCookie($recallerName);
        $this->assertNotNull($cookie, 'remember=1 must hand out a recaller cookie');

        $sessionId = Session::getId();
        $this->withCookie((string) config('session.cookie'), $sessionId)->get('/panel')->assertOk();   // EnforceIdleTimeout indexes the device here

        return [$user, $sessionId, $recallerName, (string) $cookie->getValue()];
    }

    private function pinBrowserSession(): string
    {
        $this->get('/panel')->assertOk();
        $id = Session::getId();
        $this->withCookie((string) config('session.cookie'), $id);

        return $id;
    }

    /** What a new request from the browser looks like: no guard user cached, no session attributes. */
    private function freshBrowserState(): void
    {
        Auth::forgetGuards();
        $this->flushSession();
    }

    private function recallerName(string $guard = 'web'): string
    {
        $sessionGuard = Auth::guard($guard);
        assert($sessionGuard instanceof SessionGuard);

        return $sessionGuard->getRecallerName();
    }
}
