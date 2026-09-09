<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Domain\SaaS\Enums\SuperTwoFactorPolicy;
use App\Domain\SaaS\Services\PlatformSettings;
use App\Domain\SaaS\Services\SuperTwoFactor;
use App\Domain\SaaS\Services\Totp;
use App\Domain\SaaS\Support\PlatformSettingsRegistry;
use App\Models\Central\SuperAdmin;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia;
use Symfony\Component\HttpFoundation\Response;
use Tests\Feature\SaaS\Concerns\ControlsPlatformSettings;
use Tests\TestCase;

/**
 * The platform-wide second-factor policy (`security.super_two_factor`, ARCHITECTURE §6.5): three states, two
 * kinds of operator, and the transitions between them — because the switch is only worth having if turning it
 * off keeps every enrolment and turning it back on restores the challenge on the very next request.
 */
final class SuperTwoFactorPolicyTest extends TestCase
{
    use ControlsPlatformSettings;

    private const PASSWORD = 'secret-123';

    private const HOME = 'http://super.bp.test';

    public function test_required_challenges_an_enrolled_operator_and_walls_an_unenrolled_one_on_enrolment(): void
    {
        $this->setSuperTwoFactorPolicy(SuperTwoFactorPolicy::Required);

        [$enrolled, $secret] = $this->enrolledAdmin();
        $this->login($enrolled)->assertRedirect(self::HOME.'/two-factor/challenge');
        $this->assertGuest('super');
        $this->post('/two-factor/challenge', ['code' => Totp::code($secret)])->assertRedirect(self::HOME);
        $this->assertAuthenticatedAs($enrolled, 'super');
        $this->get('/tenants')->assertOk();
        $this->post('/logout');

        $plain = $this->plainAdmin();
        $this->login($plain)->assertRedirect(self::HOME);          // the password logs them in…
        $this->assertAuthenticatedAs($plain, 'super');
        $this->get('/')->assertRedirect(self::HOME.'/security/two-factor');   // …and the console is a wall
        $this->get('/tenants')->assertRedirect(self::HOME.'/security/two-factor');
        $this->get('/security/two-factor')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Super/Auth/TwoFactor')
                ->where('policy', 'required')->where('required', true)->where('enabled', false));
    }

    public function test_optional_challenges_only_operators_who_have_enrolled(): void
    {
        $this->setSuperTwoFactorPolicy(SuperTwoFactorPolicy::Optional);

        [$enrolled, $secret] = $this->enrolledAdmin();
        $this->login($enrolled)->assertRedirect(self::HOME.'/two-factor/challenge');
        $this->assertGuest('super');
        $this->post('/two-factor/challenge', ['code' => Totp::code($secret)])->assertRedirect(self::HOME);
        $this->assertAuthenticatedAs($enrolled, 'super');
        $this->post('/logout');

        $plain = $this->plainAdmin();
        $this->login($plain)->assertRedirect(self::HOME);
        $this->assertAuthenticatedAs($plain, 'super');
        $this->get('/')->assertOk();                                 // no wall
        $this->get('/tenants')->assertOk();

        // Enrolment is open to them, just not compulsory.
        $this->get('/security/two-factor')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('policy', 'optional')->where('required', false)->where('enabled', false));
        $this->post('/security/two-factor')->assertRedirect(self::HOME.'/security/two-factor');
        $this->get('/security/two-factor')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->has('enrolment.secret'));
    }

    public function test_disabled_challenges_nobody_forces_nobody_and_wipes_nothing(): void
    {
        $this->setSuperTwoFactorPolicy(SuperTwoFactorPolicy::Disabled);

        [$enrolled, $secret] = $this->enrolledAdmin();
        $digests = $enrolled->two_factor_recovery_codes;

        $this->login($enrolled)->assertRedirect(self::HOME);        // straight in, no challenge parked
        $this->assertAuthenticatedAs($enrolled, 'super');
        $this->assertNull(session(SuperTwoFactor::SESSION_PENDING));
        $this->assertNull(session(SuperTwoFactor::SESSION_PASSED_AT), 'a password-only login must not claim a challenge it never passed');
        $this->get('/')->assertOk();
        $this->get('/tenants')->assertOk();

        // The row is untouched: the same secret still produces valid codes, the recovery digests are the same.
        $enrolled->refresh();
        $this->assertSame($secret, $enrolled->two_factor_secret);
        $this->assertSame($digests, $enrolled->two_factor_recovery_codes);
        $this->assertNotNull($enrolled->two_factor_confirmed_at);
        $this->assertTrue(app(SuperTwoFactor::class)->enabled($enrolled));
        $this->assertFalse(app(SuperTwoFactor::class)->challenges($enrolled));

        // The Security tab explains itself and offers no way to change anything.
        $this->get('/security/two-factor')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('policy', 'disabled')->where('required', false)
                ->where('enabled', true)->where('enrolment', null)->where('recovery_remaining', 8));

        $this->from('/security/two-factor')->delete('/security/two-factor', ['password' => self::PASSWORD, 'code' => Totp::code($secret)])
            ->assertSessionHasErrors('domain');
        $this->from('/security/two-factor')->post('/security/two-factor/recovery-codes', ['password' => self::PASSWORD, 'code' => Totp::code($secret, time() + Totp::PERIOD)])
            ->assertSessionHasErrors('domain');
        $this->assertSame($digests, $enrolled->refresh()->two_factor_recovery_codes, 'nothing on the read-only tab may touch the row');
        $this->post('/logout');

        $plain = $this->plainAdmin();
        $this->login($plain)->assertRedirect(self::HOME);
        $this->get('/tenants')->assertOk();
        $this->from('/security/two-factor')->post('/security/two-factor')->assertSessionHasErrors('domain');
        $this->assertNull($plain->refresh()->two_factor_secret, 'enrolment is closed while the policy is disabled');
    }

    public function test_switching_back_to_required_restores_the_challenge_on_the_very_next_request(): void
    {
        $this->setSuperTwoFactorPolicy(SuperTwoFactorPolicy::Disabled);
        [$admin, $secret] = $this->enrolledAdmin();

        $this->login($admin)->assertRedirect(self::HOME);
        $this->get('/tenants')->assertOk();

        // Another operator flips the policy — through the service, exactly as the console does.
        $this->setSuperTwoFactorPolicy(SuperTwoFactorPolicy::Required);

        // The password-only session is not good enough any more: out, and back to the login screen.
        $this->get('/tenants')->assertRedirect(self::HOME.'/login');
        $this->assertGuest('super');

        // Their enrolment survived the round trip and is what the challenge now asks for — and the challenge
        // returns them to the page the middleware threw them off.
        $this->login($admin)->assertRedirect(self::HOME.'/two-factor/challenge');
        $this->post('/two-factor/challenge', ['code' => Totp::code($secret)])->assertRedirect(self::HOME.'/tenants');
        $this->assertAuthenticatedAs($admin, 'super');
        $this->get('/tenants')->assertOk();
    }

    public function test_a_pending_challenge_parked_before_the_flip_to_disabled_is_void_and_the_next_login_is_password_only(): void
    {
        $this->setSuperTwoFactorPolicy(SuperTwoFactorPolicy::Required);
        [$admin, $secret] = $this->enrolledAdmin();
        $this->login($admin)->assertRedirect(self::HOME.'/two-factor/challenge');
        $this->assertNotNull(session(SuperTwoFactor::SESSION_PENDING));

        $this->setSuperTwoFactorPolicy(SuperTwoFactorPolicy::Disabled);

        // Not silently upgraded into a login: the half-finished login is discarded and they start again.
        $this->get('/two-factor/challenge')->assertRedirect(self::HOME.'/login');
        $this->assertNull(session(SuperTwoFactor::SESSION_PENDING));
        $this->post('/two-factor/challenge', ['code' => Totp::code($secret)])->assertRedirect(self::HOME.'/login');
        $this->assertGuest('super');

        $this->login($admin)->assertRedirect(self::HOME);
        $this->assertAuthenticatedAs($admin, 'super');
    }

    public function test_an_operator_held_on_forced_enrolment_can_still_reach_platform_settings_and_switch_the_policy(): void
    {
        $this->setSuperTwoFactorPolicy(SuperTwoFactorPolicy::Required);
        $admin = $this->plainAdmin();
        $this->login($admin)->assertRedirect(self::HOME);

        $this->get('/')->assertRedirect(self::HOME.'/security/two-factor');
        $this->get('/settings')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Super/Settings/Index'));

        // The password, not a code — they have no code, that is the point.
        $this->put('/settings/security.super_two_factor', ['value' => 'optional', 'password' => self::PASSWORD])
            ->assertRedirect(self::HOME.'/settings');

        $this->get('/')->assertOk();
        $this->assertSame(SuperTwoFactorPolicy::Optional, app(SuperTwoFactor::class)->policy());
    }

    public function test_the_default_policy_follows_the_config_flag_until_a_row_is_written(): void
    {
        app(PlatformSettings::class)->reset(PlatformSettingsRegistry::SUPER_TWO_FACTOR);

        config(['saas.two_factor.required' => true]);
        $this->assertSame(SuperTwoFactorPolicy::Required, app(SuperTwoFactor::class)->policy());

        config(['saas.two_factor.required' => false]);
        $this->assertSame(SuperTwoFactorPolicy::Optional, app(SuperTwoFactor::class)->policy());

        // A stored row wins over the env flag, whatever it says.
        $this->setSuperTwoFactorPolicy(SuperTwoFactorPolicy::Disabled);
        config(['saas.two_factor.required' => true]);
        $this->assertSame(SuperTwoFactorPolicy::Disabled, app(SuperTwoFactor::class)->policy());
    }

    /** @return array{0: SuperAdmin, 1: string} */
    private function enrolledAdmin(): array
    {
        $this->asCentral();
        $secret = Totp::generateSecret();

        return [SuperAdmin::factory()->withTwoFactor($secret)->create(['password' => self::PASSWORD]), $secret];
    }

    private function plainAdmin(): SuperAdmin
    {
        $this->asCentral();

        return SuperAdmin::factory()->create(['password' => self::PASSWORD]);
    }

    /** @return TestResponse<Response> */
    private function login(SuperAdmin $admin): TestResponse
    {
        $this->asCentral();

        return $this->post('/login', ['email' => $admin->email, 'password' => self::PASSWORD]);
    }
}
