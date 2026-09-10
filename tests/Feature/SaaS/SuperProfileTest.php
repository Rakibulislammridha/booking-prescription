<?php

declare(strict_types=1);

namespace Tests\Feature\SaaS;

use App\Domain\Audit\Enums\CentralAuditAction;
use App\Domain\Clinic\Data\StaffSessionData;
use App\Domain\SaaS\Enums\SuperTwoFactorPolicy;
use App\Domain\SaaS\Services\SuperSessionIndex;
use App\Models\Central\AuditLogCentral;
use App\Models\Central\SuperAdmin;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\SaaS\Concerns\ClearsSuperSessions;
use Tests\Feature\SaaS\Concerns\ControlsPlatformSettings;
use Tests\TestCase;

/** The operator's own account (`super.profile.*`): identity, the password, and the devices they are signed in on. */
final class SuperProfileTest extends TestCase
{
    use ClearsSuperSessions;
    use ControlsPlatformSettings;

    private const PASSWORD = 'password';

    private const STRONG = 'correct-horse-battery-9';

    /**
     * The dev and production consoles run under an explicit policy row; the suite has none and would default to
     * `required`, which sends every enrolled operator to the TOTP challenge. These screens are not about the
     * second factor, so they run under `disabled` and say so (SuperTwoFactorTest covers the challenge itself).
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->clearSuperSessions();
        $this->setSuperTwoFactorPolicy(SuperTwoFactorPolicy::Disabled);
    }

    protected function tearDown(): void
    {
        $this->clearSuperSessions();
        parent::tearDown();
    }

    public function test_the_profile_page_shows_the_account_and_the_devices_signed_in(): void
    {
        $me = $this->actingAsSuper();
        $current = $this->pinBrowserSession();
        $this->fakeDevice($me, 'Mozilla/5.0 (Linux; Android 12) Chrome/120.0');

        $this->get('/profile')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Super/Profile/Show')
                ->where('profile.email', $me->email)
                ->where('profile.two_factor', 'enabled')
                ->has('profile.two_factor_policy')
                ->has('idle_timeout_minutes')
                ->has('sessions', 2)
                ->where('sessions', fn (Collection $sessions): bool => $sessions->contains(fn (array $s): bool => $s['device'] === 'Chrome · Android' && $s['is_current'] === false)
                    && $sessions->contains(fn (array $s): bool => $s['is_current'] === true)));

        $listed = app(SuperSessionIndex::class)->all($me);
        $this->assertCount(2, $listed);
        $this->assertSame($current, collect($listed)->firstOrFail(fn ($s): bool => $s->isCurrent)->id, 'the browser session is the one marked current');
    }

    public function test_changing_the_email_requires_the_current_password_and_is_audited(): void
    {
        $me = $this->actingAsSuper();

        $this->from('/profile')->put('/profile', ['name' => $me->name, 'email' => 'moved@bp.test'])->assertSessionHasErrors('current_password');
        $this->assertNotSame('moved@bp.test', $me->refresh()->email);

        // A name change alone needs no password.
        $this->put('/profile', ['name' => 'Renamed Op', 'email' => $me->email])->assertRedirect('http://super.bp.test/profile');
        $this->assertSame('Renamed Op', $me->refresh()->name);

        $this->put('/profile', ['name' => 'Renamed Op', 'email' => 'Moved@BP.test', 'current_password' => self::PASSWORD])->assertRedirect();
        $this->assertSame('moved@bp.test', $me->refresh()->email);

        $log = AuditLogCentral::query()->where('action', CentralAuditAction::Update->value)->where('auditable_id', $me->id)->where('auditable_type', SuperAdmin::class)->latest('id')->firstOrFail();
        $this->assertSame($me->id, $log->super_admin_id);
        $this->assertSame(['email' => 'moved@bp.test'], $log->after);

        // Another operator's email is refused.
        $taken = SuperAdmin::factory()->create();
        $this->from('/profile')->put('/profile', ['name' => 'Renamed Op', 'email' => $taken->email, 'current_password' => self::PASSWORD])->assertSessionHasErrors('email');
    }

    public function test_changing_the_password_rotates_the_remember_token_and_ends_every_other_session(): void
    {
        $me = $this->actingAsSuper();
        $me->forceFill(['remember_token' => 'old-recaller'])->saveQuietly();
        $current = $this->pinBrowserSession();
        $other = $this->fakeDevice($me, 'Firefox/128.0');

        $this->from('/profile')->put('/profile/password', ['current_password' => 'wrong', 'password' => self::STRONG, 'password_confirmation' => self::STRONG])->assertSessionHasErrors('current_password');
        $this->from('/profile')->put('/profile/password', ['current_password' => self::PASSWORD, 'password' => 'weak', 'password_confirmation' => 'weak'])->assertSessionHasErrors('password');
        $this->from('/profile')->put('/profile/password', ['current_password' => self::PASSWORD, 'password' => self::PASSWORD.'1', 'password_confirmation' => self::PASSWORD.'2'])->assertSessionHasErrors('password');

        $this->put('/profile/password', ['current_password' => self::PASSWORD, 'password' => self::STRONG, 'password_confirmation' => self::STRONG])
            ->assertRedirect('http://super.bp.test/profile')
            ->assertSessionHas('flash.success', __('super.profile.flash.password_changed', ['count' => '1']));

        $me->refresh();
        $this->assertTrue(Hash::check(self::STRONG, $me->password));
        $this->assertNotSame('old-recaller', $me->remember_token);
        $this->assertSame('', Session::getHandler()->read($other), 'the other browser is signed out');
        $this->assertAuthenticatedAs($me, 'super');

        $remaining = app(SuperSessionIndex::class)->all($me);
        $this->assertCount(1, $remaining);
        $this->assertSame($current, $remaining[0]->id, 'the session that changed the password stays');

        $log = AuditLogCentral::query()->where('action', CentralAuditAction::PasswordChange->value)->where('super_admin_id', $me->id)->firstOrFail();
        $this->assertSame('self', $log->after['by'] ?? null);
        $this->assertSame(1, $log->after['other_sessions_revoked'] ?? null);

        // The new password signs in; the old one does not.
        $this->post('/logout');
        $this->from('/login')->post('/login', ['email' => $me->email, 'password' => self::PASSWORD])->assertSessionHasErrors('email');
        $this->post('/login', ['email' => $me->email, 'password' => self::STRONG])->assertRedirect('http://super.bp.test');
        $this->assertAuthenticatedAs($me, 'super');
    }

    public function test_other_sessions_can_be_signed_out_and_signing_out_this_one_ends_the_request_session(): void
    {
        $me = $this->actingAsSuper();
        $current = $this->pinBrowserSession();
        $this->fakeDevice($me, 'Firefox/128.0');
        $this->fakeDevice($me, 'Safari/17.0');
        $this->assertCount(3, app(SuperSessionIndex::class)->all($me));

        $this->delete('/profile/sessions')->assertRedirect()->assertSessionHas('flash.success', __('super.profile.sessions.revoked_many', ['count' => '2']));

        $remaining = app(SuperSessionIndex::class)->all($me);
        $this->assertCount(1, $remaining);
        $this->assertSame($current, $remaining[0]->id);
        $this->assertTrue(AuditLogCentral::query()->where('action', CentralAuditAction::SessionRevoke->value)->where('super_admin_id', $me->id)->exists());

        $this->delete('/profile/sessions/'.StaffSessionData::ref($current))->assertRedirect(route('super.login', absolute: false));
        $this->assertGuest('super');
    }

    public function test_signing_in_and_out_keeps_the_device_list_honest(): void
    {
        $this->asCentral();
        $admin = SuperAdmin::factory()->create(['email' => 'ops@bp.test', 'password' => self::PASSWORD]);

        $this->withHeader('User-Agent', 'Mozilla/5.0 (Windows NT 10.0) Chrome/120.0')->post('/login', ['email' => 'ops@bp.test', 'password' => self::PASSWORD])->assertRedirect();
        $sessions = app(SuperSessionIndex::class)->all($admin);
        $this->assertCount(1, $sessions, 'the login wrote the index entry after the regenerate');
        $this->assertSame(Session::getId(), $sessions[0]->id);
        $this->assertSame('Chrome · Windows', $sessions[0]->device());

        $this->withCookie((string) config('session.cookie'), Session::getId())->post('/logout')->assertRedirect();
        $this->assertCount(0, app(SuperSessionIndex::class)->all($admin), 'logging out drops the entry at once');
    }

    private function pinBrowserSession(): string
    {
        $this->get('/')->assertOk();
        $id = Session::getId();
        $this->withCookie((string) config('session.cookie'), $id);

        return $id;
    }

    private function fakeDevice(SuperAdmin $admin, string $userAgent): string
    {
        $id = (string) Str::random(40);
        Session::getHandler()->write($id, serialize(['_token' => Str::random(40)]));
        app(SuperSessionIndex::class)->remember($admin, $id, '10.20.30.40', $userAgent);

        return $id;
    }
}
