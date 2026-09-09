<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Domain\SaaS\Enums\SuperTwoFactorPolicy;
use App\Domain\SaaS\Services\SuperTwoFactor;
use App\Domain\SaaS\Services\Totp;
use App\Models\Central\SuperAdmin;
use Illuminate\Auth\SessionGuard;
use Illuminate\Support\Facades\Auth;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\SaaS\Concerns\ControlsPlatformSettings;
use Tests\TestCase;

/**
 * B4 regression (was tests/Feature/Audit2/SuperRememberExemptRoutesTest). The super login used to honour "remember
 * me", and the `security/two-factor/*` routes opted out of EnsureSuperTwoFactor — so a lifted recaller cookie plus
 * a password minted fresh recovery codes with no challenge. Two changes close it:
 *
 *   1. the super guard has no remember-me at all — the console can impersonate into any clinic, so it never gets a
 *      durable recaller cookie; and
 *   2. EnsureSuperTwoFactor now runs on the two-factor management routes, so an enrolled session that never passed
 *      the challenge is thrown out before it can read or regenerate recovery codes.
 */
final class SuperRememberMeTest extends TestCase
{
    use ControlsPlatformSettings;

    private const PASSWORD = 'secret-123';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setSuperTwoFactorPolicy(SuperTwoFactorPolicy::Required);
    }

    public function test_the_super_login_hands_out_no_remember_me_cookie_even_when_asked(): void
    {
        $this->asCentral();
        $secret = Totp::generateSecret();
        $admin = SuperAdmin::factory()->withTwoFactor($secret)->create(['password' => self::PASSWORD]);

        $this->post('/login', ['email' => $admin->email, 'password' => self::PASSWORD, 'remember' => 1])
            ->assertRedirect('http://super.bp.test/two-factor/challenge');

        $response = $this->post('/two-factor/challenge', ['code' => Totp::code($secret)])
            ->assertRedirect('http://super.bp.test');

        $this->assertAuthenticatedAs($admin, 'super');

        $superGuard = Auth::guard('super');
        assert($superGuard instanceof SessionGuard);
        $this->assertNull(
            $response->getCookie($superGuard->getRecallerName()),
            'the super guard must never set a remember-me cookie',
        );
    }

    public function test_an_unchallenged_super_session_cannot_read_or_regenerate_recovery_codes(): void
    {
        $this->asCentral();
        $secret = Totp::generateSecret();
        $admin = SuperAdmin::factory()->withTwoFactor($secret)->create(['password' => self::PASSWORD]);

        // A session on the super guard that never passed the challenge (what a lifted recaller used to produce).
        $this->onGuardWithoutChallenge($admin);
        $this->post('/security/two-factor/recovery-codes', ['password' => self::PASSWORD, 'code' => Totp::code($secret)])
            ->assertRedirect('http://super.bp.test/login');
        $this->assertGuest('super');

        // Nothing was minted or rotated: the eight enrolment digests are untouched.
        $this->assertSame(8, app(SuperTwoFactor::class)->recoveryCodesRemaining($admin->refresh()));

        // The management screen itself is closed to it too — it does not leak plaintext codes into the page.
        $this->onGuardWithoutChallenge($admin);
        $this->get('/security/two-factor')->assertRedirect('http://super.bp.test/login');
        $this->assertGuest('super');
    }

    public function test_a_challenged_session_still_reaches_the_two_factor_screen(): void
    {
        $this->actingAsSuper();   // enrolled AND challenge-passed (WithTenants helper)

        $this->get('/security/two-factor')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Super/Auth/TwoFactor')->where('enabled', true));
    }

    private function onGuardWithoutChallenge(SuperAdmin $admin): void
    {
        Auth::forgetGuards();
        $this->flushSession();
        $this->actingAs($admin, 'super');   // guard user set, but SESSION_PASSED_AT is absent
    }
}
