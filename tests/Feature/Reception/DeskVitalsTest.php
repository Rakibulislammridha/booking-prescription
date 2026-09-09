<?php

declare(strict_types=1);

namespace Tests\Feature\Reception;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Clinic\Enums\Role;
use App\Models\Tenant\Patient;
use App\Models\Tenant\Visit;
use App\Models\Tenant\Vital;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\Reception\Concerns\ReceptionFixtures;
use Tests\TestCase;

/**
 * BRIEF §5.G.2: vitals are entered by the compounder BEFORE the doctor sees the patient. This proves the whole
 * handoff through the product — desk board → check in → vitals screen → save — and that the doctor's writer then
 * opens with the reading already on it, written by the one `RecordVitals` action.
 */
final class DeskVitalsTest extends TestCase
{
    use ReceptionFixtures;

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

    public function test_compounder_records_vitals_from_the_desk_and_the_doctor_opens_the_writer_with_them_present(): void
    {
        $doctorUser = $this->actingAsDoctor();
        $doctor = $doctorUser->doctor()->firstOrFail();
        $session = $this->openSession(10, 10, 5, $doctor);
        $patient = Patient::factory()->create(['dob' => now()->subYears(41)->toDateString()]);
        $serial = $this->allocate($session, patientId: $patient->id);

        // --- the desk half: a receptionist, not a doctor ---------------------------------------------------------
        $receptionist = $this->actingAsStaff(Role::Receptionist);
        $this->assertTrue($receptionist->can('prescriptions.vitals.record'));

        $this->post($this->url('panel.serials.check-in', ['serial' => $serial->public_id]))->assertOk();

        $this->get($this->url('panel.reception.board'))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('Reception/Board')->where('can.record_vitals', true));

        // One click from the checked-in row: the visit is opened (idempotently) and the desk lands on the screen.
        $this->post($this->url('panel.reception.vitals.open', ['serial' => $serial->public_id]))
            ->assertRedirect();

        $visit = Visit::query()->where('serial_id', $serial->id)->firstOrFail();
        $this->assertSame($doctor->id, $visit->doctor_id);

        $this->get($this->url('panel.reception.vitals.edit', ['visit' => $visit->public_id]))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('Reception/Vitals')
                ->where('visit.public_id', $visit->public_id)
                ->where('serial.display_code', $serial->display_code)
                ->where('patient.public_id', $patient->public_id)
                ->has('patient.age_years')
                ->where('doctor.name', $doctor->name)
                ->has('vitals', 0));

        // The write goes through the Prescription module's own endpoint — there is no second write path.
        $this->postJson($this->url('panel.prescription.vitals.store', ['visit' => $visit->public_id]), [
            'bp_systolic' => 128, 'bp_diastolic' => 84, 'pulse_bpm' => 78, 'temperature_c' => 37.2,
            'spo2_percent' => 97, 'weight_kg' => 68.5, 'height_cm' => 170,
        ])->assertCreated()->assertJsonPath('vitals.bmi', 23.7);

        $vital = Vital::query()->where('visit_id', $visit->id)->firstOrFail();
        $this->assertSame($receptionist->id, $vital->recorded_by_user_id);
        $this->assertFalse($vital->edited_by_doctor);
        $this->assertNull($vital->reviewed_by_doctor_at, 'the compounder must not tick the doctor review');
        $this->assertAudited(AuditAction::View, $visit, ['screen' => 'panel.reception.vitals']);

        // The screen now shows the reading back, waiting for the doctor.
        $this->get($this->url('panel.reception.vitals.edit', ['visit' => $visit->public_id]))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->has('vitals', 1)
                ->where('vitals.0.bp_systolic', 128)
                ->where('vitals.0.bmi', 23.7)
                ->where('vitals.0.recorded_by.name', $receptionist->name)
                ->where('vitals.0.reviewed_by_doctor_at', null));

        // --- the doctor half: the writer opens with the compounder's readings already there ------------------------
        $this->actingAs($doctorUser, 'web');

        $this->get($this->url('panel.prescription.writer', ['visit' => $visit->public_id]))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('Prescription/Writer')
                ->where('visit.id', $visit->public_id)
                ->where('vitals.bp_systolic', 128)
                ->where('vitals.bp_diastolic', 84)
                ->where('vitals.spo2_percent', 97)
                ->where('vitals.weight_kg', 68.5)
                ->where('vitals.bmi', 23.7)
                ->where('vitals.recorded_by.name', $receptionist->name));
    }

    /**
     * The board is where the desk decides who to send to the compounder next, so it has to say which of the people
     * already in the waiting room have been done — without Reception reading the Prescription module's tables
     * (the answer comes from VitalsStatusQuery) and without an answer at all on the rows the question is not about.
     */
    public function test_the_board_says_which_checked_in_patients_already_have_vitals(): void
    {
        $doctor = $this->doctorWithTemplate();
        $session = $this->openSession(10, 10, 5, $doctor);
        $done = $this->allocate($session, patientId: Patient::factory()->create()->id);
        $due = $this->allocate($session, patientId: Patient::factory()->create()->id);
        $notArrived = $this->allocate($session, patientId: Patient::factory()->create()->id);

        $this->actingAsStaff(Role::Receptionist);
        $this->post($this->url('panel.serials.check-in', ['serial' => $done->public_id]))->assertOk();
        $this->post($this->url('panel.serials.check-in', ['serial' => $due->public_id]))->assertOk();

        $this->post($this->url('panel.reception.vitals.open', ['serial' => $done->public_id]))->assertRedirect();
        $visit = Visit::query()->where('serial_id', $done->id)->firstOrFail();
        $this->postJson($this->url('panel.prescription.vitals.store', ['visit' => $visit->public_id]), [
            'bp_systolic' => 132, 'bp_diastolic' => 86, 'pulse_bpm' => 80,
        ])->assertCreated();

        // The board orders serials by position, so the three rows are in the order they were allocated.
        $board = $this->getJson($this->url('panel.reception.board.data'))->assertOk();
        $board->assertJsonPath('sessions.0.serials.0.public_id', $done->public_id)
            ->assertJsonPath('sessions.0.serials.0.vitals.recorded', true)
            ->assertJsonPath('sessions.0.serials.0.vitals.readings', 1)
            ->assertJsonPath('sessions.0.serials.0.vitals.reviewed', false);  // the compounder does not tick the doctor review

        $this->assertNotNull($board->json('sessions.0.serials.0.vitals.recorded_at'), 'the desk can say when it was taken');

        $board->assertJsonPath('sessions.0.serials.1.public_id', $due->public_id)
            ->assertJsonPath('sessions.0.serials.1.vitals.recorded', false)   // checked in, nobody has taken a reading yet
            ->assertJsonPath('sessions.0.serials.2.public_id', $notArrived->public_id)
            ->assertJsonPath('sessions.0.serials.2.vitals', null);            // not arrived: not part of the question

        // The doctor reviewing the reading is a state the desk can see too.
        Vital::query()->where('visit_id', $visit->id)->firstOrFail()->forceFill(['reviewed_by_doctor_at' => now()])->save();
        $this->actingAsStaff(Role::Receptionist);
        $this->getJson($this->url('panel.reception.board.data'))->assertOk()
            ->assertJsonPath('sessions.0.serials.0.vitals.reviewed', true);
    }

    public function test_opening_the_vitals_screen_twice_reuses_the_one_visit_of_the_serial(): void
    {
        $doctor = $this->doctorWithTemplate();
        $session = $this->openSession(10, 10, 5, $doctor);
        $patient = Patient::factory()->create();
        $serial = $this->allocate($session, patientId: $patient->id);
        $this->actingAsStaff(Role::Receptionist);

        $this->post($this->url('panel.reception.vitals.open', ['serial' => $serial->public_id]))->assertRedirect();
        $this->post($this->url('panel.reception.vitals.open', ['serial' => $serial->public_id]))->assertRedirect();

        $this->assertSame(1, Visit::query()->where('serial_id', $serial->id)->count());
        $this->assertSame(1, $patient->fresh()->visit_count, 'the second open must not count a second visit');
    }

    public function test_a_role_without_the_vitals_permission_cannot_reach_the_screen(): void
    {
        $doctor = $this->doctorWithTemplate();
        $session = $this->openSession(10, 10, 5, $doctor);
        $patient = Patient::factory()->create();
        $serial = $this->allocate($session, patientId: $patient->id);

        $this->actingAsStaff(Role::Receptionist);
        $this->post($this->url('panel.reception.vitals.open', ['serial' => $serial->public_id]))->assertRedirect();
        $visit = Visit::query()->where('serial_id', $serial->id)->firstOrFail();

        $accountant = $this->actingAsStaff(Role::Accountant);
        $this->assertFalse($accountant->can('prescriptions.vitals.record'));
        $this->post($this->url('panel.reception.vitals.open', ['serial' => $serial->public_id]))->assertForbidden();
        $this->get($this->url('panel.reception.vitals.edit', ['visit' => $visit->public_id]))->assertForbidden();
        $this->postJson($this->url('panel.prescription.vitals.store', ['visit' => $visit->public_id]), ['pulse_bpm' => 80])->assertForbidden();

        $this->get($this->url('panel.reception.board'))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->where('can.record_vitals', false));
    }
}
