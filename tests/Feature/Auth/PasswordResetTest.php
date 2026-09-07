<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Domain\Audit\Enums\AuditAction;
use App\Models\Tenant\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/** Staff password reset through Laravel's broker `users` (tenant-schema password_reset_tokens) — panel.password.*. */
final class PasswordResetTest extends TestCase
{
    public function test_the_forgot_and_reset_pages_render_the_panel_auth_pages(): void
    {
        $this->asTenant('a');

        $this->get('/panel/forgot-password')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Auth/ForgotPassword'));

        $this->get('/panel/reset-password/some-token?email=desk%40test-a.test')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Auth/ResetPassword')->where('token', 'some-token')->where('email', 'desk@test-a.test'));
    }

    public function test_a_reset_link_is_sent_to_the_tenant_host_and_the_token_lives_in_the_tenant_schema(): void
    {
        Notification::fake();
        $this->asTenant('a');
        $user = User::factory()->create(['email' => 'desk@test-a.test']);

        $this->from('/panel/forgot-password')->post('/panel/forgot-password', ['email' => 'Desk@test-a.test'])
            ->assertRedirect('http://test-a.bp.test/panel/forgot-password')
            ->assertSessionHas('status', __('passwords.sent'));

        Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user): bool {
            $url = $notification->toMail($user)->actionUrl;
            $this->assertStringStartsWith('http://test-a.bp.test/panel/reset-password/', $url);
            $this->assertStringContainsString('email=desk%40test-a.test', $url);

            return true;
        });

        $this->assertSame(1, DB::table('password_reset_tokens')->where('email', 'desk@test-a.test')->count());
        $this->asTenant('b');
        $this->assertSame(0, DB::table('password_reset_tokens')->where('email', 'desk@test-a.test')->count());
    }

    public function test_an_unknown_email_is_reported_as_a_validation_error_without_leaking_across_tenants(): void
    {
        Notification::fake();
        $this->asTenant('b');
        User::factory()->create(['email' => 'only-in-b@test.test']);

        $this->asTenant('a');
        $this->from('/panel/forgot-password')->post('/panel/forgot-password', ['email' => 'only-in-b@test.test'])
            ->assertSessionHasErrors(['email' => __('passwords.user')]);

        Notification::assertNothingSent();
    }

    public function test_a_valid_token_resets_the_password_and_invalidates_the_token(): void
    {
        $this->asTenant('a');
        $user = User::factory()->create(['email' => 'desk@test-a.test', 'password' => 'old-secret', 'must_change_password' => true]);
        $token = Password::broker('users')->createToken($user);

        $this->post('/panel/reset-password', ['token' => $token, 'email' => 'desk@test-a.test', 'password' => 'new-secret-123', 'password_confirmation' => 'new-secret-123'])
            ->assertRedirect('http://test-a.bp.test/panel/login')
            ->assertSessionHas('status', __('passwords.reset'));

        $fresh = $user->fresh();
        $this->assertTrue(Hash::check('new-secret-123', (string) $fresh?->password));
        $this->assertFalse($fresh?->must_change_password);
        $this->assertFalse(Password::broker('users')->tokenExists($user, $token));
        $this->assertAudited(AuditAction::Update, $user, ['event' => 'password_reset']);

        $this->post('/panel/login', ['email' => 'desk@test-a.test', 'password' => 'new-secret-123'])->assertRedirect('http://test-a.bp.test/panel');
        $this->assertAuthenticatedAs($user, 'web');
    }

    public function test_a_wrong_or_foreign_tenant_token_is_rejected(): void
    {
        $this->asTenant('a');
        $userA = User::factory()->create(['email' => 'shared@both.test', 'password' => 'old-secret']);
        $tokenA = Password::broker('users')->createToken($userA);

        $this->from('/panel/reset-password/x')->post('/panel/reset-password', ['token' => 'nope', 'email' => 'shared@both.test', 'password' => 'new-secret-123', 'password_confirmation' => 'new-secret-123'])
            ->assertSessionHasErrors(['email' => __('passwords.token')]);

        $this->asTenant('b');
        User::factory()->create(['email' => 'shared@both.test', 'password' => 'b-secret']);
        $this->from('/panel/reset-password/x')->post('/panel/reset-password', ['token' => $tokenA, 'email' => 'shared@both.test', 'password' => 'new-secret-123', 'password_confirmation' => 'new-secret-123'])
            ->assertSessionHasErrors(['email' => __('passwords.token')]);

        $this->asTenant('a');
        $this->assertTrue(Hash::check('old-secret', (string) $userA->fresh()?->password));
    }
}
