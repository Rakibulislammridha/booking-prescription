<?php

declare(strict_types=1);

namespace Tests\Feature\Clinic;

use App\Domain\Clinic\Enums\Role;
use App\Models\Tenant\Branch;
use App\Models\Tenant\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * BRIEF §5.A staff management, including the two guards the brief asks for by name: a hospital admin may not
 * deactivate themselves, and may not take their own admin role away.
 */
final class StaffScreenTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->asTenant('a');
    }

    public function test_index_lists_by_role_with_search_and_pagination_props(): void
    {
        $admin = $this->actingAsStaff(Role::HospitalAdmin);
        $desk = User::factory()->create(['name' => 'Reception Desk']);
        $desk->assignRole(Role::Receptionist->value);

        $this->get('/panel/clinic/staff')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p
            ->component('Clinic/Staff/Index')
            ->has('users.data')
            ->has('users.meta.last_page')
            ->has('users.links.next')
            ->has('roles', 4)
            ->where('current_user_id', $admin->id)
            ->where('can.manage', true));

        $this->get('/panel/clinic/staff?role=receptionist')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p
            ->has('users.data', 1)
            ->where('users.data.0.name', 'Reception Desk')
            ->where('users.data.0.role', 'receptionist'));

        $this->get('/panel/clinic/staff?q=Reception')->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->has('users.data', 1));

        $this->get('/panel/clinic/staff?status=inactive')->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->has('users.data', 0));
    }

    public function test_create_and_edit_round_trip_without_ever_typing_a_password(): void
    {
        $this->actingAsStaff(Role::HospitalAdmin);
        $branch = Branch::query()->where('is_main', true)->firstOrFail();

        $this->get('/panel/clinic/staff/create')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p
            ->component('Clinic/Staff/Create')->has('branch_options')->has('roles', 4));

        $this->post('/panel/clinic/staff', [
            'name' => 'নতুন রিসেপশনিস্ট', 'email' => 'desk2@test-a.test', 'mobile' => '+8801711111122',
            'role' => 'receptionist', 'default_branch_id' => $branch->id, 'locale' => 'bn', 'is_active' => true, 'must_change_password' => true,
        ])->assertRedirect('/panel/clinic/staff');

        $user = User::query()->where('email', 'desk2@test-a.test')->firstOrFail();
        $this->assertTrue($user->hasRole('receptionist'));
        $this->assertTrue($user->must_change_password);

        $this->get('/panel/clinic/staff/'.$user->public_id.'/edit')->assertOk()->assertInertia(fn (AssertableInertia $p) => $p
            ->component('Clinic/Staff/Edit')
            ->where('user.email', 'desk2@test-a.test')
            ->where('is_self', false));

        $this->put('/panel/clinic/staff/'.$user->public_id, [
            'name' => 'নতুন রিসেপশনিস্ট', 'email' => 'desk2@test-a.test', 'role' => 'accountant', 'locale' => 'en', 'is_active' => true,
        ])->assertRedirect('/panel/clinic/staff');
        $this->assertSame(['accountant'], $user->fresh()?->getRoleNames()->all());
    }

    public function test_deactivating_from_the_list_works_but_never_on_yourself(): void
    {
        $admin = $this->actingAsStaff(Role::HospitalAdmin);
        $other = User::factory()->create();
        $other->assignRole(Role::Receptionist->value);

        $this->patch('/panel/clinic/staff/'.$other->public_id.'/status', ['is_active' => false])->assertRedirect();
        $this->assertFalse($other->fresh()?->is_active);

        $this->patch('/panel/clinic/staff/'.$admin->public_id.'/status', ['is_active' => false])->assertSessionHasErrors('domain');
        $this->assertTrue($admin->fresh()?->is_active, 'a hospital admin cannot lock themselves out');
    }

    public function test_a_hospital_admin_cannot_remove_their_own_admin_role(): void
    {
        $admin = $this->actingAsStaff(Role::HospitalAdmin);

        $this->put('/panel/clinic/staff/'.$admin->public_id, [
            'name' => $admin->name, 'email' => $admin->email, 'role' => 'receptionist', 'is_active' => true,
        ])->assertSessionHasErrors('domain');

        $this->assertSame(['hospital_admin'], $admin->fresh()?->getRoleNames()->all());
    }

    public function test_an_admin_may_still_demote_someone_else(): void
    {
        $this->actingAsStaff(Role::HospitalAdmin);
        $other = User::factory()->create();
        $other->assignRole(Role::HospitalAdmin->value);

        $this->put('/panel/clinic/staff/'.$other->public_id, [
            'name' => $other->name, 'email' => $other->email, 'role' => 'accountant', 'is_active' => true,
        ])->assertRedirect();
        $this->assertSame(['accountant'], $other->fresh()?->getRoleNames()->all());
    }

    public function test_sending_a_password_reset_link_queues_the_notification(): void
    {
        Notification::fake();
        $this->actingAsStaff(Role::HospitalAdmin);
        $other = User::factory()->create();
        $other->assignRole(Role::Receptionist->value);

        $this->post('/panel/clinic/staff/'.$other->public_id.'/password-reset')->assertRedirect();

        Notification::assertSentTo($other, ResetPassword::class);
    }
}
