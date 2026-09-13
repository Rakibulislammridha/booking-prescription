<?php

declare(strict_types=1);

namespace Tests\Feature\Clinic;

use App\Domain\Clinic\Actions\AssignCompounder;
use App\Domain\Clinic\Actions\UnassignCompounder;
use App\Domain\Clinic\Enums\Role;
use App\Domain\Clinic\Exceptions\CompounderNotAssigned;
use App\Domain\Clinic\Exceptions\NotACompounder;
use App\Domain\Clinic\Services\DoctorScope;
use App\Domain\Patients\Contracts\PatientAccessResolver;
use App\Domain\Shared\Actor;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\Patient;
use App\Models\Tenant\User;
use App\Models\Tenant\Visit;
use Illuminate\Support\Facades\Config;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * The foundation of the compounder feature: the assignment pivot, the screen that writes it, and DoctorScope —
 * the single answer ("which doctors is this user allowed to see?") every board, list and policy narrows through.
 */
final class CompounderAssignmentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->asTenant('a');
        // The React page for this screen is a separate change; what this test pins is the props contract it builds against.
        Config::set('inertia.testing.ensure_pages_exist', false);
    }

    public function test_the_screen_lists_the_desk_and_an_assignable_pool_of_compounders_only(): void
    {
        $this->actingAsStaff(Role::HospitalAdmin);
        $doctor = Doctor::factory()->complete()->create(['code' => 'CMP', 'slug' => 'dr-compounders']);

        $onDesk = User::factory()->withRole(Role::Compounder)->create(['name' => 'Assigned Compounder']);
        $free = User::factory()->withRole(Role::Compounder)->create(['name' => 'Free Compounder']);
        User::factory()->withRole(Role::Compounder)->inactive()->create(['name' => 'Retired Compounder']);
        User::factory()->withRole(Role::Receptionist)->create(['name' => 'Desk Receptionist']);

        $this->post("/panel/clinic/doctors/{$doctor->public_id}/compounders", ['user_public_id' => $onDesk->public_id])->assertRedirect();

        $this->get("/panel/clinic/doctors/{$doctor->public_id}/compounders")->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p
                ->component('Clinic/Doctors/Compounders')
                ->where('doctor.public_id', $doctor->public_id)
                ->has('compounders', 1)
                ->where('compounders.0.public_id', $onDesk->public_id)
                ->where('compounders.0.name', 'Assigned Compounder')
                ->where('compounders.0.is_active', true)
                ->has('compounders.0.assigned_at')
                ->has('compounders.0.assigned_by')
                ->has('available', 1)
                ->where('available.0.public_id', $free->public_id)
                ->where('can.manage', true));
    }

    public function test_assigning_and_revoking_is_idempotent_audited_and_role_checked(): void
    {
        $admin = $this->actingAsStaff(Role::HospitalAdmin);
        $doctor = Doctor::factory()->complete()->create(['code' => 'CM2', 'slug' => 'dr-compounders-2']);
        $compounder = User::factory()->withRole(Role::Compounder)->create();
        $receptionist = User::factory()->withRole(Role::Receptionist)->create();

        $assign = app(AssignCompounder::class);
        $actor = Actor::user($admin->id, Role::HospitalAdmin->value);

        $assign->handle($doctor, $compounder, $actor);
        $assign->handle($doctor, $compounder, $actor);                         // a double submit is not a 409

        $this->assertDatabaseCount('doctor_compounder', 1);
        $this->assertDatabaseHas('doctor_compounder', [
            'doctor_id' => $doctor->id, 'user_id' => $compounder->id, 'assigned_by_user_id' => $admin->id,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'action' => 'create', 'auditable_type' => Doctor::class, 'auditable_id' => $doctor->id,
        ]);

        $this->expectException(NotACompounder::class);
        $assign->handle($doctor, $receptionist, $actor);
    }

    public function test_unassigning_someone_who_is_not_on_the_desk_is_refused(): void
    {
        $admin = $this->actingAsStaff(Role::HospitalAdmin);
        $doctor = Doctor::factory()->complete()->create(['code' => 'CM3', 'slug' => 'dr-compounders-3']);
        $compounder = User::factory()->withRole(Role::Compounder)->create();

        $this->expectException(CompounderNotAssigned::class);
        app(UnassignCompounder::class)->handle($doctor, $compounder, Actor::user($admin->id));
    }

    public function test_a_doctor_administers_their_own_desk_and_no_other_doctors(): void
    {
        $user = $this->actingAsDoctor();
        $mine = Doctor::query()->where('user_id', $user->id)->firstOrFail();
        $theirs = Doctor::factory()->complete()->create(['code' => 'OTH', 'slug' => 'dr-other-desk']);
        $compounder = User::factory()->withRole(Role::Compounder)->create();

        $this->get("/panel/clinic/doctors/{$mine->public_id}/compounders")->assertOk();
        $this->post("/panel/clinic/doctors/{$mine->public_id}/compounders", ['user_public_id' => $compounder->public_id])->assertRedirect();

        $this->get("/panel/clinic/doctors/{$theirs->public_id}/compounders")->assertForbidden();
        $this->post("/panel/clinic/doctors/{$theirs->public_id}/compounders", ['user_public_id' => $compounder->public_id])->assertForbidden();

        // Someone else's desk, with a compounder really on it, so the scoped binding resolves and the POLICY is what refuses.
        $onTheirDesk = User::factory()->withRole(Role::Compounder)->create();
        app(AssignCompounder::class)->handle($theirs, $onTheirDesk, Actor::system());
        $this->delete("/panel/clinic/doctors/{$theirs->public_id}/compounders/{$onTheirDesk->public_id}")->assertForbidden();

        // A compounder who is on no desk of this doctor never reaches the controller at all.
        $this->delete("/panel/clinic/doctors/{$theirs->public_id}/compounders/{$compounder->public_id}")->assertNotFound();

        $this->delete("/panel/clinic/doctors/{$mine->public_id}/compounders/{$compounder->public_id}")->assertRedirect();
        $this->assertDatabaseMissing('doctor_compounder', ['doctor_id' => $mine->id, 'user_id' => $compounder->id]);
    }

    public function test_a_compounder_may_not_open_the_assignment_screen_at_all(): void
    {
        $doctor = Doctor::factory()->complete()->create(['code' => 'CM4', 'slug' => 'dr-compounders-4']);
        $this->actingAsStaff(Role::Compounder);

        $this->get("/panel/clinic/doctors/{$doctor->public_id}/compounders")->assertForbidden();
        $this->post("/panel/clinic/doctors/{$doctor->public_id}/compounders", ['user_public_id' => 'NOTAREALPUBLICID0000000000'])->assertForbidden();
    }

    /** null = unrestricted, [] = nothing, a list = exactly those doctors — and the answer is re-read every time. */
    public function test_doctor_scope_restricts_a_compounder_and_nobody_else(): void
    {
        $admin = $this->actingAsStaff(Role::HospitalAdmin);
        $scope = app(DoctorScope::class);

        $a = Doctor::factory()->complete()->create(['code' => 'SC1', 'slug' => 'dr-scope-a']);
        $b = Doctor::factory()->complete()->create(['code' => 'SC2', 'slug' => 'dr-scope-b']);
        $c = Doctor::factory()->complete()->create(['code' => 'SC3', 'slug' => 'dr-scope-c']);

        $receptionist = User::factory()->withRole(Role::Receptionist)->create();
        $compounder = User::factory()->withRole(Role::Compounder)->create();

        $this->assertNull($scope->doctorIds($receptionist));
        $this->assertNull($scope->doctorIds($admin));
        $this->assertTrue($scope->allows($receptionist, $a->id));
        $this->assertTrue($scope->allows($receptionist, null));

        $this->assertSame([], $scope->doctorIds($compounder), 'no assignment must mean nothing, not everything');
        $this->assertFalse($scope->allows($compounder, $a->id));

        $assign = app(AssignCompounder::class);
        $actor = Actor::user($admin->id, Role::HospitalAdmin->value);
        $assign->handle($a, $compounder, $actor);
        $assign->handle($b, $compounder, $actor);

        $this->assertEqualsCanonicalizing([$a->id, $b->id], $scope->doctorIds($compounder));
        $this->assertTrue($scope->allows($compounder, $a->id));
        $this->assertTrue($scope->allows($compounder, $b->id));
        $this->assertFalse($scope->allows($compounder, $c->id));
        $this->assertFalse($scope->allows($compounder, null), 'a row with no doctor cannot be proven to be theirs');

        // An admin who is somehow assigned stays unrestricted: the account that repairs a clinic never locks itself out.
        $dualRole = User::factory()->withRole(Role::Compounder)->create();
        $dualRole->assignRole(Role::HospitalAdmin->value);
        $assign->handle($a, $dualRole, $actor);
        $this->assertNull($scope->doctorIds($dualRole));

        // Revocation bites on the very next call, on the SAME scope instance — nothing is memoised.
        app(UnassignCompounder::class)->handle($a, $compounder, $actor);
        $this->assertSame([$b->id], $scope->doctorIds($compounder));
    }

    public function test_the_patient_resolver_gives_a_compounder_only_their_doctors_patients(): void
    {
        $admin = $this->actingAsStaff(Role::HospitalAdmin);
        $mine = Doctor::factory()->complete()->create(['code' => 'PR1', 'slug' => 'dr-res-mine']);
        $theirs = Doctor::factory()->complete()->create(['code' => 'PR2', 'slug' => 'dr-res-theirs']);

        $compounder = User::factory()->withRole(Role::Compounder)->create();
        $treatedByMine = Patient::factory()->create();
        $treatedByTheirs = Patient::factory()->create();
        $untreated = Patient::factory()->create();

        Visit::factory()->create(['patient_id' => $treatedByMine->id, 'doctor_id' => $mine->id]);
        Visit::factory()->create(['patient_id' => $treatedByTheirs->id, 'doctor_id' => $theirs->id]);

        $resolver = app(PatientAccessResolver::class);

        // Assigned to nobody: no patient at all, not even one nobody has treated.
        foreach ([$treatedByMine, $treatedByTheirs, $untreated] as $patient) {
            $this->assertFalse($resolver->canAccess($compounder, $patient));
        }

        app(AssignCompounder::class)->handle($mine, $compounder, Actor::user($admin->id));

        $this->assertTrue($resolver->canAccess($compounder, $treatedByMine));
        $this->assertFalse($resolver->canAccess($compounder, $treatedByTheirs));
        $this->assertFalse($resolver->canAccess($compounder, $untreated), 'an untreated patient belongs to no doctor');

        $query = Patient::query();
        $resolver->constrain($compounder, $query);
        $this->assertSame([$treatedByMine->id], $query->pluck('id')->map(fn ($id) => (int) $id)->all());
    }

    /** The doctor path must come through the generalisation byte-identical, untreated leg and all. */
    public function test_a_doctor_still_sees_their_own_patients_and_the_untreated_ones(): void
    {
        $user = $this->actingAsDoctor();
        $mine = Doctor::query()->where('user_id', $user->id)->firstOrFail();
        $theirs = Doctor::factory()->complete()->create(['code' => 'PR3', 'slug' => 'dr-res-other']);

        $treatedByMe = Patient::factory()->create();
        $treatedByThem = Patient::factory()->create();
        $untreated = Patient::factory()->create();

        Visit::factory()->create(['patient_id' => $treatedByMe->id, 'doctor_id' => $mine->id]);
        Visit::factory()->create(['patient_id' => $treatedByThem->id, 'doctor_id' => $theirs->id]);

        $resolver = app(PatientAccessResolver::class);

        $this->assertTrue($resolver->canAccess($user, $treatedByMe));
        $this->assertTrue($resolver->canAccess($user, $untreated), 'the desk → doctor hand-off must not 403');
        $this->assertFalse($resolver->canAccess($user, $treatedByThem));

        $query = Patient::query();
        $resolver->constrain($user, $query);
        $ids = $query->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->assertContains($treatedByMe->id, $ids);
        $this->assertContains($untreated->id, $ids);
        $this->assertNotContains($treatedByThem->id, $ids);
    }
}
