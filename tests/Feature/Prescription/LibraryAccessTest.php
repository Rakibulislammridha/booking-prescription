<?php

declare(strict_types=1);

namespace Tests\Feature\Prescription;

use App\Domain\Clinic\Actions\AssignCompounder;
use App\Domain\Clinic\Enums\Role;
use App\Domain\Shared\Actor;
use App\Models\Tenant\AdviceSnippet;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\Prescription;
use App\Models\Tenant\PrescriptionTemplate;
use App\Models\Tenant\User;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * CLAIM: the clinic's prescribing library — the template list, one template, the advice palette — and the
 * interaction checker are open to every staff user DoctorScope does not restrict, and closed to every user it does.
 * The line is "restricted", not "prescriber": a receptionist has had all four since they shipped and is not what the
 * compounder work was about.
 *
 * Four endpoints, and until this file not one of them had a test in either direction. That is how they were
 * mis-fixed twice in one wave: `view` on a template was "any active staff user", so a compounder could page through
 * every doctor's content; the first fix demanded `prescriptions.write || prescriptions.view.any`, which shut the
 * hole AND took the shared-template list, the advice palette and the checker away from the receptionist — and the
 * suite stayed green through both, because green meant untested.
 *
 * So both directions are asserted on every endpoint, and the compounder's refusal is asserted on a sheet they are
 * allowed to READ: the question is not whose patient this is (they may print that very sheet at the desk, BRIEF
 * §5.G.4), it is that the prescribing content behind it is the doctors', not the desk's.
 */
final class LibraryAccessTest extends TestCase
{
    use PrescriptionTestHelpers;

    private Doctor $doctor;

    /** A draft of that doctor's, which is what the interaction checker is run over. */
    private Prescription $prescription;

    /** doctor_id null: the clinic library every doctor and every unrestricted desk sees. */
    private PrescriptionTemplate $clinicTemplate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->asTenant('a');

        [, $doctor, $visit] = $this->doctorWithOpenVisit();
        $this->doctor = $doctor;
        $this->prescription = $this->draftFor($visit, $doctor);
        $this->clinicTemplate = PrescriptionTemplate::factory()->shared()->create();
        AdviceSnippet::factory()->create(['text' => 'Drink plenty of water']);   // the palette row the index must hand back
    }

    /** @param  array<string, mixed>  $params */
    private function url(string $name, array $params = []): string
    {
        return route($name, $params, false);
    }

    /** The four reads, in the order the writer performs them. */
    private function assertLibraryAnswers(): void
    {
        $this->getJson($this->url('panel.prescription.templates.index'))->assertOk()
            ->assertJsonPath('data.0.id', $this->clinicTemplate->id);
        $this->getJson($this->url('panel.prescription.templates.show', ['template' => $this->clinicTemplate->id]))->assertOk()
            ->assertJsonPath('data.name', $this->clinicTemplate->name);
        $this->getJson($this->url('panel.prescription.snippets.index'))->assertOk()
            ->assertJsonPath('data.0.text', 'Drink plenty of water');
        $this->postJson($this->url('panel.prescription.prescriptions.check', ['prescription' => $this->prescription->public_id]), ['items' => []])
            ->assertOk()->assertJsonStructure(['alerts', 'issue_blocked_by', 'computed', 'catalog_version']);
    }

    private function assertLibraryRefuses(): void
    {
        $this->getJson($this->url('panel.prescription.templates.index'))->assertForbidden();
        $this->getJson($this->url('panel.prescription.templates.show', ['template' => $this->clinicTemplate->id]))->assertForbidden();
        $this->getJson($this->url('panel.prescription.snippets.index'))->assertForbidden();
        $this->postJson($this->url('panel.prescription.prescriptions.check', ['prescription' => $this->prescription->public_id]), ['items' => []])->assertForbidden();
    }

    public function test_the_receptionist_keeps_the_template_list_the_palette_and_the_checker(): void
    {
        $this->actingAsStaff(Role::Receptionist);

        $this->assertLibraryAnswers();
    }

    /** The doctors whose content it is, and the admin who administers it, are the obvious half — and were never asserted either. */
    public function test_so_do_the_doctor_and_the_hospital_admin(): void
    {
        $this->actingAsDoctor();
        $this->assertLibraryAnswers();

        $this->actingAsStaff(Role::HospitalAdmin);
        $this->assertLibraryAnswers();
    }

    /**
     * The compounder, on the desk of the very doctor whose draft this is. They may read that sheet — `view` is the
     * wide door the desk prints through — and the library behind it is still not theirs, which is the distinction
     * the four rules had to be rewritten to express.
     */
    public function test_a_compounder_is_refused_all_four_on_the_desk_they_were_hired_for(): void
    {
        /** @var User $compounder */
        $compounder = $this->actingAsStaff(Role::Compounder);
        app(AssignCompounder::class)->handle($this->doctor, $compounder, Actor::system());

        $this->assertTrue(Gate::forUser($compounder)->allows('view', $this->prescription), 'the refusals below are about the library, not about whose patient this is');
        $this->assertFalse(Gate::forUser($compounder)->allows('view', $this->clinicTemplate));

        $this->assertLibraryRefuses();
    }

    /**
     * And the unassigned one: DoctorScope answers `[]` for them, which is non-null and therefore restricted. An
     * empty list is a deny everywhere in this codebase, never "no filter to apply".
     */
    public function test_a_compounder_on_nobodys_desk_is_refused_the_same_four(): void
    {
        $this->actingAsStaff(Role::Compounder);

        $this->assertLibraryRefuses();
    }

    /**
     * The scope is re-read on every request, here as everywhere: a desk that loses its assignment loses the sheet
     * it was reading, and one that is given the role mid-shift loses the library it had a moment ago.
     */
    public function test_the_library_closes_on_the_very_next_request_when_the_role_is_added(): void
    {
        /** @var User $user */
        $user = $this->actingAsStaff(Role::Receptionist);
        $this->getJson($this->url('panel.prescription.snippets.index'))->assertOk();

        $user->assignRole(Role::Compounder->value);
        app(AssignCompounder::class)->handle($this->doctor, $user->fresh(), Actor::system());

        $this->getJson($this->url('panel.prescription.snippets.index'))->assertForbidden();
        $this->getJson($this->url('panel.prescription.templates.index'))->assertForbidden();
    }
}
