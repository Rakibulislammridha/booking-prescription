<?php

declare(strict_types=1);

namespace Tests\Feature\Clinic;

use App\Domain\Clinic\Enums\Role;
use App\Domain\Clinic\Services\Settings;
use App\Http\Middleware\EnforceIdleTimeout;
use Tests\TestCase;

/**
 * BRIEF §5.N — the idle timeout. `users.session_timeout_minutes` and `settings.security.session_timeout_minutes`
 * were editable, validated 5–1440, exposed in the UI and read by nothing: an admin who set 15 minutes got no
 * protection and no warning. These tests are the proof they now mean something.
 */
final class SessionTimeoutTest extends TestCase
{
    public function test_a_staff_session_idle_past_the_tenant_setting_is_ended_with_a_message(): void
    {
        $this->asTenant('a');
        app(Settings::class)->set('security.session_timeout_minutes', 15);
        $this->actingAsStaff(Role::Receptionist);

        $this->get('/panel')->assertOk();
        $this->assertAuthenticated('web');

        $this->travel(14)->minutes();
        $this->get('/panel')->assertOk();          // still inside the window, and it resets the clock

        $this->travel(16)->minutes();
        $this->get('/panel')
            ->assertRedirect(route('panel.login', absolute: false))
            ->assertSessionHas('flash.error', __('auth.idle_timeout', ['minutes' => 15]));

        $this->assertGuest('web');
    }

    public function test_the_per_user_value_overrides_the_tenant_setting_in_both_directions(): void
    {
        $this->asTenant('a');
        app(Settings::class)->set('security.session_timeout_minutes', 600);
        $user = $this->actingAsStaff(Role::Doctor);
        $user->forceFill(['session_timeout_minutes' => 5])->save();

        $this->get('/panel')->assertOk();
        $this->travel(6)->minutes();
        $this->get('/panel')->assertRedirect(route('panel.login', absolute: false));
        $this->assertGuest('web');

        // …and a generous per-user value survives a strict tenant default.
        $this->asTenant('a');
        app(Settings::class)->set('security.session_timeout_minutes', 5);
        $patient = $this->actingAsStaff(Role::Receptionist);
        $patient->forceFill(['session_timeout_minutes' => 600])->save();

        $this->get('/panel')->assertOk();
        $this->travel(30)->minutes();
        $this->get('/panel')->assertOk();
        $this->assertAuthenticated('web');
    }

    public function test_an_expired_xhr_gets_401_and_the_reason_rather_than_a_redirect_it_cannot_follow(): void
    {
        $this->asTenant('a');
        app(Settings::class)->set('security.session_timeout_minutes', 5);
        $this->actingAsStaff(Role::Receptionist);

        $this->get('/panel')->assertOk();
        $this->travel(6)->minutes();

        $this->getJson('/panel')
            ->assertStatus(401)
            ->assertJsonPath('message', __('auth.idle_timeout', ['minutes' => 5]));
    }

    public function test_the_api_surface_neither_expires_nor_extends_the_idle_clock(): void
    {
        $this->asTenant('a');
        app(Settings::class)->set('security.session_timeout_minutes', 10);
        $this->actingAsStaff(Role::Receptionist);

        $this->get('/panel')->assertOk();
        $marker = session(EnforceIdleTimeout::SESSION_KEY);

        // A background poll must not be mistaken for a person at the desk.
        $this->travel(5)->minutes();
        $this->getJson('/api/ping')->assertOk();
        $this->assertSame($marker, session(EnforceIdleTimeout::SESSION_KEY), 'the heartbeat did not reset the idle clock');

        $this->travel(6)->minutes();
        $this->get('/panel')->assertRedirect(route('panel.login', absolute: false));
    }

    /**
     * B3 (was tests/Feature/Audit2/IdleClockPollingTest). The reception board and the doctor's queue each poll a
     * PANEL data route every 5 s. Those polls used to carry `idle:web` like a click and silently reset the clock,
     * so a desk left open never timed out. Now a background poll is treated like the /api/ping heartbeat: it
     * neither extends nor expires the idle clock, and only a genuine navigation does.
     */
    public function test_the_reception_board_poll_does_not_extend_the_idle_clock(): void
    {
        $this->assertBackgroundPollDoesNotKeepTheSessionAlive('panel.reception.board.data', Role::Receptionist);
    }

    public function test_the_doctor_queue_poll_does_not_extend_the_idle_clock(): void
    {
        $this->assertBackgroundPollDoesNotKeepTheSessionAlive('panel.queue.today.data', Role::HospitalAdmin);
    }

    private function assertBackgroundPollDoesNotKeepTheSessionAlive(string $pollRoute, Role $role): void
    {
        $this->asTenant('a');
        app(Settings::class)->set('security.session_timeout_minutes', 5);
        $this->actingAsStaff($role);

        $this->get('/panel')->assertOk();
        $marker = session(EnforceIdleTimeout::SESSION_KEY);

        // Nothing but the screen's own 5-second poll for 40 minutes (sampled every 4). It must not touch the clock.
        for ($i = 0; $i < 10; $i++) {
            $this->travel(4)->minutes();
            $this->getJson(route($pollRoute, absolute: false))->assertOk();
            $this->assertSame($marker, session(EnforceIdleTimeout::SESSION_KEY), 'a background poll reset the idle clock');
        }

        // 44 minutes with no human action against a 5-minute limit: the next real navigation ends the session.
        $this->travel(4)->minutes();
        $this->get('/panel')->assertRedirect(route('panel.login', absolute: false));
        $this->assertGuest('web');
    }

    public function test_a_genuine_navigation_still_extends_the_idle_clock(): void
    {
        $this->asTenant('a');
        app(Settings::class)->set('security.session_timeout_minutes', 5);
        $this->actingAsStaff(Role::Receptionist);

        $this->get('/panel')->assertOk();

        // A real page load every 4 minutes keeps the 5-minute session alive indefinitely; a poll would not.
        for ($i = 0; $i < 10; $i++) {
            $this->travel(4)->minutes();
            $this->get('/panel')->assertOk();
        }

        $this->assertAuthenticated('web');
    }

    public function test_the_super_console_uses_its_own_configured_limit(): void
    {
        config(['session.idle_timeout_minutes' => 5]);
        $this->actingAsSuper();

        $this->get('/')->assertOk();
        $this->travel(6)->minutes();
        $this->get('/')->assertRedirect(route('super.login', absolute: false));
        $this->assertGuest('super');
    }
}
