<?php

declare(strict_types=1);

namespace Tests\Feature\SaaS;

use App\Domain\Audit\Enums\CentralAuditAction;
use App\Domain\SaaS\Actions\Admins\DeactivateSuperAdmin;
use App\Domain\SaaS\Actions\Admins\DeleteSuperAdmin;
use App\Domain\SaaS\Enums\SuperTwoFactorPolicy;
use App\Domain\SaaS\Exceptions\LastActiveSuperAdmin;
use App\Domain\SaaS\Notifications\SuperSetPasswordLink;
use App\Domain\SaaS\Services\SuperSessionIndex;
use App\Domain\SaaS\Services\SuperTwoFactor;
use App\Models\Central\AuditLogCentral;
use App\Models\Central\SuperAdmin;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\SaaS\Concerns\ClearsSuperSessions;
use Tests\Feature\SaaS\Concerns\ControlsPlatformSettings;
use Tests\TestCase;

/**
 * The console's Admins screens (`super.admins.*`): who can open the console, how an account is created, and the
 * three things that must never be one click away — locking yourself out, locking everyone out, and stripping a
 * colleague's second factor without proving who you are.
 */
final class SuperAdminsTest extends TestCase
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

    public function test_the_list_shows_every_operator_with_their_second_factor_and_last_sign_in(): void
    {
        $me = $this->actingAsSuper();
        $other = SuperAdmin::factory()->create(['name' => 'Bob Ops', 'last_login_at' => now()->subDay(), 'last_login_ip' => '10.0.0.9']);
        SuperAdmin::factory()->inactive()->withTwoFactor()->create(['name' => 'Former Ops']);

        $this->get('/admins')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Super/Admins/Index')
                ->has('admins', 3)
                ->where('active_count', 2)
                ->where('admins.0.is_active', true)
                ->where('admins.2.name', 'Former Ops')
                ->where('admins.2.is_active', false)
                ->where('admins.2.two_factor', 'enabled'));

        $this->get('/admins/'.$other->id.'/edit')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Super/Admins/Edit')
                ->where('admin.id', $other->id)
                ->where('admin.two_factor', 'none')
                ->where('admin.last_login_ip', '10.0.0.9')
                ->where('admin.never_used', false)
                ->where('admin.is_self', false)
                ->where('admin.is_last_active', false));

        $this->get('/admins/'.$me->id.'/edit')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('admin.is_self', true));
    }

    public function test_creating_an_operator_with_a_password_re_asks_the_creator_password_and_is_audited(): void
    {
        $me = $this->actingAsSuper();

        $this->from('/admins')->post('/admins', ['name' => 'New Op', 'email' => 'New@BP.test', 'password' => self::STRONG, 'password_confirmation' => self::STRONG, 'current_password' => 'wrong'])
            ->assertSessionHasErrors('current_password');
        $this->assertDatabaseMissing('public.super_admins', ['email' => 'new@bp.test']);

        // The same strength rule as `super:create`.
        $this->from('/admins')->post('/admins', ['name' => 'New Op', 'email' => 'new@bp.test', 'password' => 'short1', 'password_confirmation' => 'short1', 'current_password' => self::PASSWORD])
            ->assertSessionHasErrors('password');

        $this->post('/admins', ['name' => 'New Op', 'email' => 'New@BP.test', 'password' => self::STRONG, 'password_confirmation' => self::STRONG, 'current_password' => self::PASSWORD])
            ->assertRedirect();

        $created = SuperAdmin::query()->where('email', 'new@bp.test')->firstOrFail();
        $this->assertTrue($created->is_active);
        $this->assertTrue(Hash::check(self::STRONG, $created->password));

        $log = AuditLogCentral::query()->where('action', CentralAuditAction::Create->value)->where('auditable_type', SuperAdmin::class)->where('auditable_id', $created->id)->firstOrFail();
        $this->assertSame($me->id, $log->super_admin_id);
        $this->assertSame('new@bp.test', $log->after['email'] ?? null);
        $this->assertSame('set', $log->after['password'] ?? null);

        // Duplicate email is refused.
        $this->from('/admins')->post('/admins', ['name' => 'Dup', 'email' => 'new@bp.test', 'send_link' => true, 'current_password' => self::PASSWORD])
            ->assertSessionHasErrors('email');
    }

    public function test_creating_an_operator_without_a_password_mails_a_set_password_link_that_works_once(): void
    {
        Notification::fake();
        $this->actingAsSuper();

        $this->post('/admins', ['name' => 'Linked Op', 'email' => 'linked@bp.test', 'send_link' => true, 'current_password' => self::PASSWORD])->assertRedirect();

        $created = SuperAdmin::query()->where('email', 'linked@bp.test')->firstOrFail();
        $token = null;

        Notification::assertSentTo($created, SuperSetPasswordLink::class, function (SuperSetPasswordLink $notification) use ($created, &$token): bool {
            $url = $notification->url($created);
            $this->assertStringStartsWith('http://super.bp.test/set-password/', $url);
            $this->assertStringContainsString('email=linked%40bp.test', $url);
            $token = explode('?', basename($url))[0];

            $mail = $notification->toMail($created);
            $this->assertSame(__('super.admins.mail.set_password_subject'), $mail->subject);

            return true;
        });

        $this->assertSame(1, DB::table('public.password_reset_tokens')->where('email', 'linked@bp.test')->count());
        $this->assertNotNull($token);

        // The link lands on the super host as a guest, and the new password must meet the rule.
        $this->post('/logout');
        $this->get('/set-password/'.$token.'?email=linked%40bp.test')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Super/Auth/SetPassword')->where('email', 'linked@bp.test'));

        $this->from('/set-password/'.$token)->post('/set-password', ['token' => $token, 'email' => 'linked@bp.test', 'password' => 'weak', 'password_confirmation' => 'weak'])
            ->assertSessionHasErrors('password');

        $this->post('/set-password', ['token' => $token, 'email' => 'linked@bp.test', 'password' => self::STRONG, 'password_confirmation' => self::STRONG])
            ->assertRedirect('http://super.bp.test/login');

        $this->assertTrue(Hash::check(self::STRONG, $created->refresh()->password));
        $this->assertTrue(AuditLogCentral::query()->where('action', CentralAuditAction::PasswordChange->value)->where('super_admin_id', $created->id)->exists());

        // Spent.
        $this->from('/set-password/'.$token)->post('/set-password', ['token' => $token, 'email' => 'linked@bp.test', 'password' => self::STRONG, 'password_confirmation' => self::STRONG])
            ->assertSessionHasErrors('email');

        // And they can sign in with it.
        $this->post('/login', ['email' => 'linked@bp.test', 'password' => self::STRONG])->assertRedirect('http://super.bp.test');
        $this->assertAuthenticatedAs($created, 'super');
    }

    public function test_editing_identity_is_audited_with_before_and_after(): void
    {
        $me = $this->actingAsSuper();
        $other = SuperAdmin::factory()->create(['name' => 'Old Name', 'email' => 'old@bp.test']);

        $this->put('/admins/'.$other->id, ['name' => 'New Name', 'email' => 'old@bp.test'])->assertRedirect('http://super.bp.test/admins/'.$other->id.'/edit');

        $this->assertSame('New Name', $other->refresh()->name);
        $log = AuditLogCentral::query()->where('action', CentralAuditAction::Update->value)->where('auditable_id', $other->id)->where('auditable_type', SuperAdmin::class)->latest('id')->firstOrFail();
        $this->assertSame($me->id, $log->super_admin_id);
        $this->assertSame(['name' => 'Old Name'], $log->before);
        $this->assertSame(['name' => 'New Name'], $log->after);
    }

    public function test_a_password_set_by_a_colleague_re_asks_theirs_and_ends_every_session_of_the_target(): void
    {
        $this->actingAsSuper();
        $other = SuperAdmin::factory()->create();
        $device = $this->fakeDevice($other, 'Firefox/128.0');

        $this->from('/admins/'.$other->id.'/edit')->put('/admins/'.$other->id, ['name' => $other->name, 'email' => $other->email, 'password' => self::STRONG, 'password_confirmation' => self::STRONG])
            ->assertSessionHasErrors('current_password');

        $this->put('/admins/'.$other->id, ['name' => $other->name, 'email' => $other->email, 'password' => self::STRONG, 'password_confirmation' => self::STRONG, 'current_password' => self::PASSWORD])
            ->assertRedirect();

        $this->assertTrue(Hash::check(self::STRONG, $other->refresh()->password));
        $this->assertSame('', Session::getHandler()->read($device), 'the target session is gone');
        $this->assertTrue(AuditLogCentral::query()->where('action', CentralAuditAction::PasswordChange->value)->where('auditable_id', $other->id)->exists());
    }

    public function test_deactivation_kills_every_session_and_the_remember_token_and_reactivation_restores_access(): void
    {
        $me = $this->actingAsSuper();
        $victim = SuperAdmin::factory()->withTwoFactor()->create(['remember_token' => 'old-recaller']);
        $device = $this->fakeDevice($victim, 'Chrome/120.0 (Windows NT 10.0)');
        $this->assertCount(1, app(SuperSessionIndex::class)->all($victim));

        $this->post('/admins/'.$victim->id.'/deactivate')->assertRedirect();

        $victim->refresh();
        $this->assertFalse($victim->is_active);
        $this->assertNotSame('old-recaller', $victim->remember_token, 'the recaller was rotated');
        $this->assertSame('', Session::getHandler()->read($device), 'a deactivated account cannot stay logged in');
        $this->assertCount(0, app(SuperSessionIndex::class)->all($victim));

        $log = AuditLogCentral::query()->where('action', CentralAuditAction::Deactivate->value)->where('auditable_id', $victim->id)->firstOrFail();
        $this->assertSame($me->id, $log->super_admin_id);
        $this->assertSame(['is_active' => true], $log->before);
        $this->assertSame(false, $log->after['is_active'] ?? null);
        $this->assertSame(1, $log->after['sessions_revoked'] ?? null);

        // Their password no longer opens the console…
        $this->post('/logout');
        $this->from('/login')->post('/login', ['email' => $victim->email, 'password' => self::PASSWORD])->assertSessionHasErrors('email');

        // …until reactivated, which keeps their second factor exactly as it was.
        $this->actingAs($me, 'super')->withSession([SuperTwoFactor::SESSION_PASSED_AT => time()]);
        $this->post('/admins/'.$victim->id.'/reactivate')->assertRedirect();
        $this->assertTrue($victim->refresh()->is_active);
        $this->assertTrue(app(SuperTwoFactor::class)->enabled($victim));
        $this->assertTrue(AuditLogCentral::query()->where('action', CentralAuditAction::Reactivate->value)->where('auditable_id', $victim->id)->where('auditable_type', SuperAdmin::class)->exists());
    }

    public function test_an_operator_cannot_deactivate_delete_or_reset_themselves(): void
    {
        $me = $this->actingAsSuper();
        SuperAdmin::factory()->create();   // so "last active" is not the reason

        $this->from('/admins/'.$me->id.'/edit')->post('/admins/'.$me->id.'/deactivate')->assertRedirect('http://super.bp.test/admins/'.$me->id.'/edit')->assertSessionHasErrors('domain');
        $this->assertTrue($me->refresh()->is_active);

        $this->from('/admins/'.$me->id.'/edit')->post('/admins/'.$me->id.'/two-factor/reset', ['password' => self::PASSWORD])->assertSessionHasErrors('domain');
        $this->assertTrue(app(SuperTwoFactor::class)->enabled($me->refresh()));

        $this->from('/admins/'.$me->id.'/edit')->delete('/admins/'.$me->id, ['password' => self::PASSWORD])->assertSessionHasErrors('domain');
        $this->assertNotNull(SuperAdmin::query()->find($me->id));
        $this->assertAuthenticatedAs($me, 'super');
    }

    public function test_the_last_active_operator_cannot_be_deactivated_or_deleted(): void
    {
        $me = $this->actingAsSuper();
        // Make every other operator inactive so the OTHER account is the last active one — then act on it as a
        // deactivated colleague could not, but a stale session might: the guard is in the action, not the screen.
        $last = SuperAdmin::factory()->create();
        $me->forceFill(['is_active' => false])->saveQuietly();

        try {
            app(DeactivateSuperAdmin::class)->handle($last, $me);
            $this->fail('the last active operator was deactivated');
        } catch (LastActiveSuperAdmin $e) {
            $this->assertSame('super.admins.last_active', $e->code());
        }

        $this->assertTrue($last->refresh()->is_active);

        try {
            app(DeleteSuperAdmin::class)->handle($last, $me);
            $this->fail('the last active operator was deleted');
        } catch (LastActiveSuperAdmin) {
            $this->assertNotNull(SuperAdmin::query()->find($last->id));
        }
    }

    public function test_resetting_a_colleagues_second_factor_re_asks_the_password_clears_the_enrolment_and_is_audited(): void
    {
        $me = $this->actingAsSuper();
        $locked = SuperAdmin::factory()->withTwoFactor(recoveryCodes: 5)->create();
        $device = $this->fakeDevice($locked, 'Safari/17.0');

        $this->from('/admins/'.$locked->id.'/edit')->post('/admins/'.$locked->id.'/two-factor/reset', ['password' => 'nope'])->assertSessionHasErrors('password');
        $this->assertTrue(app(SuperTwoFactor::class)->enabled($locked->refresh()));

        $this->post('/admins/'.$locked->id.'/two-factor/reset', ['password' => self::PASSWORD])->assertRedirect();

        $locked->refresh();
        $this->assertNull($locked->two_factor_secret);
        $this->assertNull($locked->two_factor_recovery_codes);
        $this->assertNull($locked->two_factor_confirmed_at);
        $this->assertSame('', Session::getHandler()->read($device));

        $log = AuditLogCentral::query()->where('action', CentralAuditAction::TwoFactorReset->value)->where('auditable_id', $locked->id)->firstOrFail();
        $this->assertSame($me->id, $log->super_admin_id, 'the row names the colleague who did it, not the owner');
        $this->assertSame(['two_factor' => 'enabled', 'recovery_codes' => 5], $log->before);
        $this->assertSame('none', $log->after['two_factor'] ?? null);

        // No `two_factor_disabled` row: that action is the owner's own doing.
        $this->assertFalse(AuditLogCentral::query()->where('action', CentralAuditAction::TwoFactorDisabled->value)->where('auditable_id', $locked->id)->exists());

        // And they can now sign in on the password alone (under `optional`/`required` they would be sent to enrol).
        $this->post('/logout');
        $this->post('/login', ['email' => $locked->email, 'password' => self::PASSWORD]);
        $this->assertAuthenticatedAs($locked, 'super');
    }

    public function test_delete_removes_only_an_account_that_was_never_used_and_offers_deactivate_otherwise(): void
    {
        $me = $this->actingAsSuper();
        $fresh = SuperAdmin::factory()->create(['email' => 'typo@bp.test']);
        $used = SuperAdmin::factory()->create(['last_login_at' => now()]);

        $this->from('/admins/'.$fresh->id.'/edit')->delete('/admins/'.$fresh->id, ['password' => 'wrong'])->assertSessionHasErrors('password');

        $this->delete('/admins/'.$fresh->id, ['password' => self::PASSWORD])->assertRedirect('http://super.bp.test/admins');
        $this->assertNull(SuperAdmin::query()->withTrashed()->find($fresh->id), 'really gone, so the email is free again');

        $log = AuditLogCentral::query()->where('action', CentralAuditAction::Delete->value)->where('auditable_type', SuperAdmin::class)->where('auditable_id', $fresh->id)->firstOrFail();
        $this->assertSame($me->id, $log->super_admin_id);
        $this->assertSame('typo@bp.test', $log->before['email'] ?? null);

        $this->from('/admins/'.$used->id.'/edit')->delete('/admins/'.$used->id, ['password' => self::PASSWORD])->assertSessionHasErrors('domain');
        $this->assertNotNull(SuperAdmin::query()->find($used->id));
        $this->assertFalse(DeleteSuperAdmin::neverUsed($used));

        // The email can be reused now.
        $this->post('/admins', ['name' => 'Again', 'email' => 'typo@bp.test', 'send_link' => true, 'current_password' => self::PASSWORD])->assertRedirect();
        $this->assertSame(1, SuperAdmin::query()->where('email', 'typo@bp.test')->count());
    }

    public function test_sending_a_set_password_link_to_an_existing_operator_is_audited(): void
    {
        Notification::fake();
        $me = $this->actingAsSuper();
        $other = SuperAdmin::factory()->create();

        $this->post('/admins/'.$other->id.'/password-link')->assertRedirect();

        Notification::assertSentTo($other, SuperSetPasswordLink::class);
        $log = AuditLogCentral::query()->where('action', CentralAuditAction::Update->value)->where('auditable_id', $other->id)->where('auditable_type', SuperAdmin::class)->latest('id')->firstOrFail();
        $this->assertSame($me->id, $log->super_admin_id);
        $this->assertSame('sent', $log->after['set_password_link'] ?? null);
    }

    public function test_the_admin_screens_are_closed_to_guests(): void
    {
        $this->asCentral();
        $admin = SuperAdmin::factory()->create();

        $this->get('/admins')->assertRedirect('http://super.bp.test/login');
        $this->post('/admins/'.$admin->id.'/deactivate')->assertRedirect('http://super.bp.test/login');
        $this->assertTrue($admin->refresh()->is_active);
    }

    /** A second browser, without a second test client: a real session payload plus its index entry. */
    private function fakeDevice(SuperAdmin $admin, string $userAgent): string
    {
        $id = (string) Str::random(40);
        Session::getHandler()->write($id, serialize(['_token' => Str::random(40)]));
        app(SuperSessionIndex::class)->remember($admin, $id, '10.20.30.40', $userAgent);

        return $id;
    }
}
