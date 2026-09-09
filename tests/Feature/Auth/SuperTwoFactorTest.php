<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Domain\SaaS\Services\SuperTwoFactor;
use App\Domain\SaaS\Services\Totp;
use App\Models\Central\AuditLogCentral;
use App\Models\Central\ImpersonationToken;
use App\Models\Central\SuperAdmin;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * Two-factor authentication on the `super` guard (ARCHITECTURE §6.5).
 *
 * The console can impersonate into any clinic's patient records, so these are adversarial tests, not happy-path
 * ones: the wrong code, the SAME code twice, a phone whose clock drifts, a recovery code used twice, a guesser
 * who keeps going, and — the one that would actually hurt — a half-finished login trying to mint an impersonation
 * token before it has proved anything.
 */
final class SuperTwoFactorTest extends TestCase
{
    private const PASSWORD = 'secret-123';

    public function test_enrolment_is_confirm_before_enable_and_mints_recovery_codes(): void
    {
        $admin = $this->actingAsSuperWithout2fa();

        $this->get('/security/two-factor')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Super/Auth/TwoFactor')
                ->where('enabled', false)->where('required', true)->where('enrolment', null));

        $this->post('/security/two-factor')->assertRedirect('http://super.bp.test/security/two-factor');

        $admin->refresh();
        $secret = (string) $admin->two_factor_secret;
        $this->assertNotSame('', $secret);
        $this->assertNull($admin->two_factor_confirmed_at, 'a generated secret is NOT an enabled factor');
        $this->assertFalse(app(SuperTwoFactor::class)->enabled($admin));

        // The screen shows the QR and the typed-out key; the secret is base32 and the QR is an inline SVG.
        $this->get('/security/two-factor')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('enrolment.secret', Totp::formatSecret($secret))
                ->where('enrolment.otpauth_uri', fn (string $uri) => str_starts_with($uri, 'otpauth://totp/'))
                ->where('enrolment.qr_svg', fn (string $qr) => str_starts_with($qr, 'data:image/svg+xml'))
                ->etc());

        // A wrong code does not enable anything.
        $this->from('/security/two-factor')->post('/security/two-factor/confirm', ['code' => '000000'])->assertSessionHasErrors('code');
        $this->assertNull($admin->refresh()->two_factor_confirmed_at);

        $this->post('/security/two-factor/confirm', ['code' => Totp::code($secret)])
            ->assertRedirect('http://super.bp.test/security/two-factor')
            ->assertSessionHas(SuperTwoFactor::SESSION_RECOVERY_CODES);

        $admin->refresh();
        $this->assertNotNull($admin->two_factor_confirmed_at);
        $this->assertCount(8, (array) $admin->two_factor_recovery_codes);
        $this->assertSame(1, AuditLogCentral::query()->where('super_admin_id', $admin->id)->where('action', 'two_factor_enabled')->count());

        // Stored hashed AND encrypted: the raw column is neither the codes nor readable JSON.
        $raw = (string) DB::table('public.super_admins')->where('id', $admin->id)->value('two_factor_recovery_codes');
        $this->assertJson(Crypt::decryptString($raw));
        foreach ((array) $admin->two_factor_recovery_codes as $digest) {
            $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $digest);
        }
    }

    public function test_login_stops_at_the_challenge_and_a_correct_code_finishes_it(): void
    {
        [$admin, $secret] = $this->enrolledAdmin();

        $this->asCentral();
        $this->post('/login', ['email' => $admin->email, 'password' => self::PASSWORD])
            ->assertRedirect('http://super.bp.test/two-factor/challenge');

        $this->assertGuest('super');

        $this->get('/two-factor/challenge')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Super/Auth/TwoFactorChallenge')->where('recovery_available', true));

        $this->post('/two-factor/challenge', ['code' => Totp::code($secret)])->assertRedirect('http://super.bp.test');

        $this->assertAuthenticatedAs($admin, 'super');
        $this->assertSame(1, AuditLogCentral::query()->where('super_admin_id', $admin->id)->where('action', 'login')->count());
        $this->get('/')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->component('Super/Dashboard'));
    }

    public function test_a_wrong_code_is_refused_audited_and_never_authenticates(): void
    {
        [$admin] = $this->enrolledAdmin();
        $this->startLogin($admin);

        $this->from('/two-factor/challenge')->post('/two-factor/challenge', ['code' => '000000'])->assertSessionHasErrors('code');

        $this->assertGuest('super');
        $this->assertSame(1, AuditLogCentral::query()->where('super_admin_id', $admin->id)->where('action', 'two_factor_failed')->count());
        $this->assertSame(0, AuditLogCentral::query()->where('super_admin_id', $admin->id)->where('action', 'login')->count());
    }

    /** TOTP's structural hole: a code stays arithmetically valid for the rest of its 30-second step. */
    public function test_a_replayed_code_inside_the_same_window_is_refused(): void
    {
        [$admin, $secret] = $this->enrolledAdmin();
        $code = Totp::code($secret);

        $this->startLogin($admin);
        $this->post('/two-factor/challenge', ['code' => $code])->assertRedirect('http://super.bp.test');
        $this->assertAuthenticatedAs($admin, 'super');

        $this->post('/logout');
        $this->assertGuest('super');

        // Same code, same time step, seconds later — arithmetically correct and refused anyway.
        $this->startLogin($admin);
        $this->from('/two-factor/challenge')->post('/two-factor/challenge', ['code' => $code])->assertSessionHasErrors('code');
        $this->assertGuest('super');
        $this->assertSame(1, AuditLogCentral::query()->where('super_admin_id', $admin->id)->where('action', 'two_factor_failed')->count());
    }

    public function test_one_step_of_clock_skew_is_accepted_and_two_is_not(): void
    {
        $twoFactor = app(SuperTwoFactor::class);
        [$admin, $secret] = $this->enrolledAdmin();

        $this->assertTrue($twoFactor->verifyCode($admin, Totp::code($secret, time() - Totp::PERIOD)), 'a phone 30s slow still works');
        $this->assertTrue($twoFactor->verifyCode($admin, Totp::code($secret, time() + Totp::PERIOD)), 'a phone 30s fast still works');
        $this->assertFalse($twoFactor->verifyCode($admin, Totp::code($secret, time() - 3 * Totp::PERIOD)), '90s of drift is refused');
    }

    public function test_a_recovery_code_works_once(): void
    {
        $admin = $this->actingAsSuperWithout2fa();
        $secret = app(SuperTwoFactor::class)->beginEnrolment($admin);
        $codes = app(SuperTwoFactor::class)->confirm($admin->refresh(), Totp::code($secret));
        $this->assertIsArray($codes);
        $code = $codes[0];

        $this->post('/logout');
        $this->startLogin($admin);
        $this->post('/two-factor/challenge', ['recovery_code' => $code])->assertRedirect('http://super.bp.test');
        $this->assertAuthenticatedAs($admin, 'super');
        $this->assertSame(7, count((array) $admin->refresh()->two_factor_recovery_codes));
        $this->assertSame(1, AuditLogCentral::query()->where('super_admin_id', $admin->id)->where('action', 'two_factor_recovery_used')->count());

        $this->post('/logout');
        $this->startLogin($admin);
        $this->from('/two-factor/challenge')->post('/two-factor/challenge', ['recovery_code' => $code])->assertSessionHasErrors('recovery_code');
        $this->assertGuest('super');
    }

    public function test_the_challenge_locks_out_after_repeated_failures(): void
    {
        [$admin, $secret] = $this->enrolledAdmin();
        $this->startLogin($admin);

        for ($attempt = 1; $attempt <= 5; $attempt++) {
            $this->from('/two-factor/challenge')->post('/two-factor/challenge', ['code' => '000000'])->assertSessionHasErrors('code');
        }

        // The sixth attempt is refused before the code is even looked at — and so is a CORRECT one.
        $this->from('/two-factor/challenge')->post('/two-factor/challenge', ['code' => Totp::code($secret)])
            ->assertSessionHasErrors('code');

        $this->assertGuest('super');
        $this->assertSame(5, AuditLogCentral::query()->where('super_admin_id', $admin->id)->where('action', 'two_factor_failed')->count(),
            'the locked-out attempt is refused without burning an audit row for a code nobody read');
    }

    public function test_an_unchallenged_session_reaches_no_super_route_and_mints_no_impersonation_token(): void
    {
        [$admin] = $this->enrolledAdmin();
        $tenant = $this->tenant('a');

        $this->asCentral();
        $this->post('/login', ['email' => $admin->email, 'password' => self::PASSWORD])->assertRedirect('http://super.bp.test/two-factor/challenge');
        $this->assertGuest('super');

        foreach (['/', '/tenants', '/plans', '/usage', '/audit'] as $url) {
            $this->get($url)->assertRedirect('http://super.bp.test/login');
        }

        $this->post('/tenants/'.$tenant->public_id.'/impersonate')->assertRedirect('http://super.bp.test/login');
        $this->assertSame(0, ImpersonationToken::query()->count(), 'a half-finished login minted a token');
    }

    /** Belt and braces: an enrolled operator on the guard WITHOUT the challenge marker is logged out, not served. */
    public function test_a_session_missing_the_challenge_marker_is_logged_out(): void
    {
        [$admin] = $this->enrolledAdmin();

        $this->asCentral();
        $this->actingAs($admin, 'super');
        $this->withSession([SuperTwoFactor::SESSION_PASSED_AT => null]);
        $this->flushSession();

        $this->actingAs($admin, 'super');
        $this->get('/tenants')->assertRedirect('http://super.bp.test/login');
        $this->assertGuest('super');
    }

    public function test_enrolment_is_forced_before_the_console_opens_when_it_is_required(): void
    {
        $admin = $this->actingAsSuperWithout2fa();

        $this->get('/')->assertRedirect('http://super.bp.test/security/two-factor');
        $this->get('/tenants')->assertRedirect('http://super.bp.test/security/two-factor');
        $this->get('/security/two-factor')->assertOk();
        $this->post('/logout')->assertRedirect('http://super.bp.test/login');   // the one other door

        // With enforcement off the same operator works normally — the flag is real, not decorative.
        config(['saas.two_factor.required' => false]);
        $this->actingAs($admin, 'super');
        $this->get('/')->assertOk();
    }

    /**
     * The panel bundle's connection heartbeat runs on every super screen, INCLUDING the enrolment screen an
     * un-enrolled operator is held on. A 403 there paints a red OFFLINE bar across a console that is perfectly
     * online — exactly the bug the route's own comment says it exists to prevent.
     */
    public function test_the_connection_heartbeat_answers_from_inside_forced_enrolment(): void
    {
        $this->actingAsSuperWithout2fa();

        $this->getJson('/api/ping')->assertOk()->assertJsonPath('tenant', null);

        $this->post('/logout');
        $this->getJson('/api/ping')->assertOk();       // and to a guest, which is what a heartbeat is for
    }

    public function test_disabling_needs_the_password_and_a_current_code_and_is_audited(): void
    {
        $admin = $this->actingAsSuperWithout2fa();
        $secret = app(SuperTwoFactor::class)->beginEnrolment($admin);
        app(SuperTwoFactor::class)->confirm($admin->refresh(), Totp::code($secret));   // spends this step

        // Wrong password is refused even with a valid code.
        $this->from('/security/two-factor')->delete('/security/two-factor', ['password' => 'wrong-one', 'code' => $this->freshCode($secret)])
            ->assertSessionHasErrors('password');
        $this->assertNotNull($admin->refresh()->two_factor_confirmed_at);

        // Right password, NO code is refused: a lifted password alone must not turn the factor off (B4).
        $this->from('/security/two-factor')->delete('/security/two-factor', ['password' => self::PASSWORD])
            ->assertSessionHasErrors('code');
        $this->assertNotNull($admin->refresh()->two_factor_confirmed_at);

        // Password AND a current code: now it turns off.
        $this->delete('/security/two-factor', ['password' => self::PASSWORD, 'code' => $this->freshCode($secret)])
            ->assertRedirect('http://super.bp.test/security/two-factor');

        $admin->refresh();
        $this->assertNull($admin->two_factor_confirmed_at);
        $this->assertNull($admin->two_factor_secret);
        $this->assertSame(1, AuditLogCentral::query()->where('super_admin_id', $admin->id)->where('action', 'two_factor_disabled')->count());
    }

    public function test_recovery_codes_can_be_reissued_with_password_and_code_and_the_old_ones_stop_working(): void
    {
        $admin = $this->actingAsSuperWithout2fa();
        $secret = app(SuperTwoFactor::class)->beginEnrolment($admin);
        $first = (array) app(SuperTwoFactor::class)->confirm($admin->refresh(), Totp::code($secret));   // spends this step

        // Password alone (the old exempt-route behaviour) no longer regenerates anything.
        $this->from('/security/two-factor')->post('/security/two-factor/recovery-codes', ['password' => self::PASSWORD])
            ->assertSessionHasErrors('code');
        $this->assertTrueButUnchanged($admin, $first);

        $this->post('/security/two-factor/recovery-codes', ['password' => self::PASSWORD, 'code' => $this->freshCode($secret)])
            ->assertRedirect('http://super.bp.test/security/two-factor');

        $this->assertFalse(app(SuperTwoFactor::class)->verifyRecoveryCode($admin->refresh(), (string) $first[0]));
        $this->assertSame(8, app(SuperTwoFactor::class)->recoveryCodesRemaining($admin));
    }

    /**
     * The old recovery codes still work after a refused (code-less) regenerate.
     *
     * @param  array<int, string>  $first
     */
    private function assertTrueButUnchanged(SuperAdmin $admin, array $first): void
    {
        $digests = (array) $admin->refresh()->two_factor_recovery_codes;
        $this->assertCount(8, $digests);
        $this->assertSame(hash('sha256', (string) $first[0]), $digests[0] ?? null, 'a code-less regenerate must not have replaced the codes');
    }

    /** A TOTP code for the NEXT step, so it is not the one a preceding confirm()/verify already spent. */
    private function freshCode(string $secret): string
    {
        return Totp::code($secret, time() + Totp::PERIOD);
    }

    /** An operator who is signed in and enrolled but not required to be — nothing forces them anywhere. */
    private function actingAsSuperWithout2fa(): SuperAdmin
    {
        $this->asCentral();
        $admin = SuperAdmin::factory()->create(['password' => self::PASSWORD]);
        $this->actingAs($admin, 'super');
        $this->withSession([SuperTwoFactor::SESSION_PASSED_AT => time()]);

        return $admin;
    }

    /** @return array{0: SuperAdmin, 1: string} */
    private function enrolledAdmin(): array
    {
        $this->asCentral();
        $secret = Totp::generateSecret();

        return [SuperAdmin::factory()->withTwoFactor($secret)->create(['password' => self::PASSWORD]), $secret];
    }

    /** Get as far as the challenge: password accepted, nobody authenticated. */
    private function startLogin(SuperAdmin $admin): void
    {
        $this->asCentral();
        $this->post('/login', ['email' => $admin->email, 'password' => self::PASSWORD])
            ->assertRedirect('http://super.bp.test/two-factor/challenge');
    }
}
