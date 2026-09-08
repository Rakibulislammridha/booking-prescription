<?php

declare(strict_types=1);

namespace Tests\Feature\Clinic;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Clinic\Actions\UpdateStaffUser;
use App\Domain\Clinic\Data\StaffSessionData;
use App\Domain\Clinic\Data\StaffUserData;
use App\Domain\Clinic\Enums\Role;
use App\Domain\Clinic\Services\StaffSessionIndex;
use App\Domain\Shared\Actor;
use App\Models\Tenant\User;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * BRIEF §5.N — staff device management. ARCHITECTURE §6.1 asserted this worked "via the `sessions_by_user` Redis
 * index in the Clinic module"; the index did not exist. These tests are what makes that sentence true.
 */
final class StaffSessionsTest extends TestCase
{
    protected function tearDown(): void
    {
        // The index lives in Redis, which no database transaction rolls back.
        Redis::connection()->del(...array_map(fn (int $id) => "bp:sessions_by_user:9001:{$id}", range(1, 60)));
        parent::tearDown();
    }

    public function test_signing_in_puts_the_device_in_the_index_and_the_screen_lists_it(): void
    {
        $this->asTenant('a');
        $user = User::factory()->create(['email' => 'desk@test-a.test']);
        $user->assignRole(Role::HospitalAdmin->value);

        $this->post('/panel/login', ['email' => 'desk@test-a.test', 'password' => 'password'])->assertRedirect();
        $this->withCookie((string) config('session.cookie'), Session::getId());

        $this->withHeader('User-Agent', 'Mozilla/5.0 (Windows NT 10.0) Chrome/120.0')
            ->get('/panel/clinic/staff/'.$user->public_id.'/sessions')
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->component('Clinic/Staff/Sessions')
                ->has('sessions', 1)
                ->where('sessions.0.is_current', true)
                ->where('sessions.0.device', 'Chrome · Windows'));

        $sessions = app(StaffSessionIndex::class)->all($user);

        $this->assertCount(1, $sessions, 'the session the browser actually holds is the one in the index');
        $this->assertSame(Session::getId(), $sessions[0]->id, 'the id survived the login regenerate()');
    }

    public function test_a_second_device_is_listed_and_can_be_thrown_off(): void
    {
        $this->asTenant('a');
        $user = $this->actingAsStaff(Role::HospitalAdmin);

        $this->pinBrowserSession();                             // the middleware indexes this device
        $other = $this->fakeDevice($user, 'Mozilla/5.0 (Linux; Android 12) Chrome/120.0');

        $index = app(StaffSessionIndex::class);
        $this->assertCount(2, $index->all($user));

        $ref = StaffSessionData::ref($other);

        $this->delete('/panel/clinic/staff/'.$user->public_id.'/sessions/'.$ref)
            ->assertRedirect()
            ->assertSessionHas('flash.success', __('clinic.sessions.revoked'));

        $this->assertSame('', Session::getHandler()->read($other), 'the revoked session payload is gone');
        $this->assertCount(1, $index->all($user), 'and it is out of the device list');
        $this->assertAuthenticated('web');

        $audit = $this->assertAudited(AuditAction::Logout, $user, ['event' => 'session_revoked']);
        $this->assertSame($ref, $audit->context['session_ref']);
        $this->assertSame('Chrome · Android', $audit->context['device']);
    }

    public function test_ending_every_other_session_keeps_the_one_you_are_using(): void
    {
        $this->asTenant('a');
        $user = $this->actingAsStaff(Role::HospitalAdmin);
        $current = $this->pinBrowserSession();

        $this->fakeDevice($user, 'Firefox/128.0');
        $this->fakeDevice($user, 'Safari/17.0');
        $this->assertCount(3, app(StaffSessionIndex::class)->all($user));

        $this->delete('/panel/clinic/staff/'.$user->public_id.'/sessions')->assertRedirect();

        $remaining = app(StaffSessionIndex::class)->all($user);
        $this->assertCount(1, $remaining);
        $this->assertSame($current, $remaining[0]->id);
        $this->assertAuthenticated('web');
    }

    public function test_ending_your_own_current_session_signs_you_out_here_and_now(): void
    {
        $this->asTenant('a');
        $user = $this->actingAsStaff(Role::Receptionist);
        $current = $this->pinBrowserSession();

        $this->delete('/panel/clinic/staff/'.$user->public_id.'/sessions/'.StaffSessionData::ref($current))
            ->assertRedirect(route('panel.login', absolute: false));

        $this->assertGuest('web');
    }

    public function test_you_may_manage_your_own_sessions_but_not_someone_elses_without_the_permission(): void
    {
        $this->asTenant('a');
        $me = $this->actingAsStaff(Role::Receptionist);
        $colleague = User::factory()->create();
        $colleague->assignRole(Role::Receptionist->value);

        $this->get('/panel/clinic/staff/'.$me->public_id.'/sessions')->assertOk();
        $this->get('/panel/clinic/staff/'.$colleague->public_id.'/sessions')->assertForbidden();
        $this->delete('/panel/clinic/staff/'.$colleague->public_id.'/sessions')->assertForbidden();
    }

    public function test_deactivating_an_account_ends_every_session_it_still_holds(): void
    {
        $this->asTenant('a');
        $admin = $this->actingAsStaff(Role::HospitalAdmin);
        $victim = User::factory()->create();
        $victim->assignRole(Role::Receptionist->value);

        $device = $this->fakeDevice($victim, 'Chrome/120.0 (Windows NT 10.0)');
        $this->assertCount(1, app(StaffSessionIndex::class)->all($victim));

        app(UpdateStaffUser::class)->handle($victim, new StaffUserData(
            name: $victim->name, email: $victim->email, role: Role::Receptionist, mobile: null,
            defaultBranchId: $victim->default_branch_id, locale: 'bn', isActive: false,
        ), Actor::user($admin->id));

        $this->assertSame('', Session::getHandler()->read($device), 'a deactivated account cannot stay logged in');
        $this->assertCount(0, app(StaffSessionIndex::class)->all($victim));
    }

    /**
     * Laravel's test client sends no session cookie, and `StartSession` therefore mints a NEW session id on every
     * request (the store keeps its data in memory, which is why `actingAs` still works). A real browser does send
     * it, so these tests hand the cookie back the way a browser would — otherwise "your current session" would be
     * a different session on every request and nothing about device management could be asserted.
     */
    private function pinBrowserSession(): string
    {
        $this->get('/panel')->assertOk();
        $id = Session::getId();
        $this->withCookie((string) config('session.cookie'), $id);

        return $id;
    }

    /** A second browser, without a second test client: a real session payload plus its index entry. */
    private function fakeDevice(User $user, string $userAgent): string
    {
        $id = (string) Str::random(40);
        Session::getHandler()->write($id, serialize(['_token' => Str::random(40)]));
        app(StaffSessionIndex::class)->remember($user, $id, '10.20.30.40', $userAgent);

        return $id;
    }
}
