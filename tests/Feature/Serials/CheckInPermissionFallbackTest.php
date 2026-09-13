<?php

declare(strict_types=1);

namespace Tests\Feature\Serials;

use App\Domain\Clinic\Enums\Permission;
use App\Domain\Clinic\Enums\Role;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\Permission as PermissionRow;
use App\Models\Tenant\Role as RoleRow;
use App\Models\Tenant\SessionInstance;
use App\Models\Tenant\User;
use Inertia\Testing\AssertableInertia;
use Spatie\Permission\PermissionRegistrar;
use Tests\Feature\Serials\Concerns\SerialFixtures;
use Tests\TestCase;

/**
 * CLAIM: on a tenant whose schema has never been told that `serials.check-in` exists, the front desk still marks
 * patients arrived. SerialPolicy::checkIn OR-s the three roles that held the move before it became a permission
 * (receptionist | hospital_admin | doctor) precisely so that a missed seeder run costs nobody their desk.
 *
 * This is not a hypothetical. `tenants:migrate --seed` skips suspended tenants (DEPLOYMENT.md §3, OPERATIONS.md
 * §2.2), Spatie's Gate::before swallows PermissionDoesNotExist and answers false (HasPermissions::checkPermissionTo),
 * and the failure is therefore silent: every arrival POST 403s, nothing is logged, and postpone — which kept its own
 * role list — goes on working. "I can postpone this patient but I cannot mark them arrived" is not a sentence a
 * front desk can act on, so the fallback is asserted here as a rule, in both of the shapes an unseeded tenant takes:
 * the permission ROW missing entirely, and the row present but never synced onto the role.
 *
 * THIS WHOLE FILE IS TRANSITIONAL and is deleted in the same commit as `SerialPolicy::heldCheckInBeforeThePermission`
 * — the release after the one that ships `serials.check-in`, once every reactivated straggler has been through the
 * seeder. It is a file of its own rather than a method in SerialHttpTest so that the deletion is one `rm` and cannot
 * leave a half-removed rule behind.
 *
 * What the fallback must NOT do is widen anybody, so the two directions are asserted together: the roles that held
 * check-in get it back, the roles that never had it (compounder, accountant) stay out even though the permission
 * they came through has vanished, and the DoctorScope conjunct — which sits OUTSIDE the OR — still narrows a
 * dual-role account to its own doctor.
 */
final class CheckInPermissionFallbackTest extends TestCase
{
    use SerialFixtures;

    protected function setUp(): void
    {
        parent::setUp();
        $this->asTenant('a');
    }

    /** @param  array<string, mixed>  $params */
    private function url(string $name, array $params = []): string
    {
        return route($name, $params, false);
    }

    /**
     * A tenant that never ran RolesAndPermissionsSeeder for this permission: the row is not in the schema at all,
     * which is what makes `can()` a silent false rather than a loud exception.
     */
    private function neverSeeded(): void
    {
        PermissionRow::query()->where('name', Permission::SerialsCheckIn->value)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /** The other shape: the permission exists (a later deploy created it) but this tenant's roles were never synced. */
    private function seededButNotSyncedOnto(Role $role): void
    {
        RoleRow::query()->where('name', $role->value)->firstOrFail()->revokePermissionTo(Permission::SerialsCheckIn->value);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_the_desk_still_marks_a_patient_arrived_when_the_permission_row_does_not_exist(): void
    {
        $user = $this->actingAsStaff(Role::Receptionist);
        $session = $this->openSession();
        $arriving = $this->allocate($session);
        $postponing = $this->allocate($session);
        // A later session for the same doctor, so postpone is a real success rather than a business refusal.
        SessionInstance::factory()->on($this->today(), 'B', '17:00', '21:00')->quotas(10, 10, 5)
            ->create(['doctor_id' => $session->doctor_id, 'branch_id' => $this->mainBranch()->id]);

        $this->neverSeeded();

        // The silent half of the failure, asserted so the rest of this test is known to be exercising the fallback
        // and not simply the permission: Spatie answers "no" to a permission that is not there.
        $this->assertFalse($user->fresh()?->can(Permission::SerialsCheckIn->value), 'a missing permission is a false, never an exception — that is why this goes unnoticed');

        $this->postJson($this->url('panel.serials.check-in', ['serial' => $arriving->public_id]))
            ->assertOk()->assertJsonPath('serial.status', 'checked_in');

        // no-show and reinstate delegate to the same ability, so they come back with it or they stay broken with it.
        $this->postJson($this->url('panel.serials.no-show', ['serial' => $arriving->public_id]))->assertOk();
        $this->postJson($this->url('panel.serials.reinstate', ['serial' => $arriving->public_id]))->assertOk();

        // The asymmetry the docblock names, in one pair of lines: postpone never needed the permission and works,
        // and without the fallback the line above it would be the only thing on this desk answering 403.
        $this->postJson($this->url('panel.serials.postpone', ['serial' => $postponing->public_id]), ['reason' => 'doctor away'])->assertOk();

        // …and the board still OFFERS the tick. A server-side fallback that the page cannot see is not a working
        // desk: SessionTile renders the arrival control on `can.check_in` alone.
        $this->get($this->url('panel.reception.board'))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('Reception/Board')->where('can.check_in', true));
    }

    public function test_the_same_holds_when_the_permission_exists_but_was_never_synced_onto_the_role(): void
    {
        $user = $this->actingAsStaff(Role::Receptionist);
        $serial = $this->allocate($this->openSession());

        $this->seededButNotSyncedOnto(Role::Receptionist);

        $this->assertFalse($user->fresh()?->can(Permission::SerialsCheckIn->value));
        $this->postJson($this->url('panel.serials.check-in', ['serial' => $serial->public_id]))
            ->assertOk()->assertJsonPath('serial.status', 'checked_in');
    }

    /** The other two roles the fallback names — the hospital admin who repairs the clinic, and the doctor. */
    public function test_the_hospital_admin_and_the_doctor_keep_the_move_too(): void
    {
        $this->actingAsStaff(Role::HospitalAdmin);
        $session = $this->openSession();
        $forAdmin = $this->allocate($session);
        $forDoctor = $this->allocate($session);

        $this->neverSeeded();

        $this->postJson($this->url('panel.serials.check-in', ['serial' => $forAdmin->public_id]))->assertOk();

        $this->actingAsDoctor();
        $this->postJson($this->url('panel.serials.check-in', ['serial' => $forDoctor->public_id]))->assertOk();
    }

    /**
     * The fallback is a list of three roles and nothing else. A compounder holds none of them — the permission was
     * their ONLY door — so an unseeded tenant costs them the desk, which is the correct and deliberate answer: the
     * alternative is a transitional rule that hands a new role something it never had. An accountant, who never had
     * it under either regime, is the control.
     */
    public function test_the_fallback_widens_nobody_it_is_the_old_role_list_and_not_a_free_pass(): void
    {
        $this->actingAsStaff(Role::Receptionist);
        $doctor = Doctor::factory()->complete()->create();
        $serial = $this->allocate($this->openSession(10, 10, 5, $doctor));

        $this->neverSeeded();

        $compounder = $this->actingAsStaff(Role::Compounder);
        $compounder->assignedDoctors()->sync([$doctor->id]);   // on the right desk, and still refused
        $this->postJson($this->url('panel.serials.check-in', ['serial' => $serial->public_id]))->assertForbidden();

        $this->actingAsStaff(Role::Accountant);
        $this->postJson($this->url('panel.serials.check-in', ['serial' => $serial->public_id]))->assertForbidden();

        $this->assertSame('booked', $serial->fresh()?->status->value, 'both refusals were refusals, not silent no-ops');
    }

    /**
     * The conjunct that must survive the fallback: `checkIn` reads
     * `active && (permission || oldRoles) && DoctorScope`, with the scope OUTSIDE the OR. An account that carries
     * receptionist AND compounder therefore comes through the fallback's receptionist leg on an unseeded tenant —
     * and is still narrowed to the doctor they were assigned to. If the scope had been written inside the OR, this
     * is the account that would have walked out with the whole branch on exactly the tenant nobody is watching.
     */
    public function test_a_dual_role_account_comes_through_the_fallback_and_is_still_scoped_to_its_own_doctor(): void
    {
        $this->actingAsStaff(Role::Receptionist);
        $mine = Doctor::factory()->complete()->create();
        $theirs = Doctor::factory()->complete()->create();
        $ours = $this->allocate($this->openSession(10, 10, 5, $mine));
        $notOurs = $this->allocate($this->openSession(10, 10, 5, $theirs));

        $this->neverSeeded();

        /** @var User $user */
        $user = $this->actingAsStaff(Role::Receptionist);
        $user->assignRole(Role::Compounder->value);
        $user->assignedDoctors()->sync([$mine->id]);

        $this->postJson($this->url('panel.serials.check-in', ['serial' => $ours->public_id]))
            ->assertOk()->assertJsonPath('serial.status', 'checked_in');
        $this->postJson($this->url('panel.serials.check-in', ['serial' => $notOurs->public_id]))->assertForbidden();
        $this->assertSame('booked', $notOurs->fresh()?->status->value);
    }
}
