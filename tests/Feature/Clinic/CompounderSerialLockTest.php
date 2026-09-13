<?php

declare(strict_types=1);

namespace Tests\Feature\Clinic;

use App\Domain\Clinic\Actions\AssignCompounder;
use App\Domain\Clinic\Enums\Role;
use App\Domain\Shared\Actor;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\Patient;
use App\Models\Tenant\Prescription;
use App\Models\Tenant\SessionInstance;
use App\Models\Tenant\User;
use App\Models\Tenant\Visit;
use Illuminate\Support\Facades\Gate;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\Clinic\Concerns\CompounderFixtures;
use Tests\Feature\Prescription\PrescriptionTestHelpers;
use Tests\TestCase;

/**
 * CLAIM: "he can't be able to edit the serial number" — and the clinical half of the same sentence, "he can manage
 * those specific doctor's patients", which stops short of writing a prescription.
 *
 * The first test is the lock itself, and it is asserted on the compounder's OWN doctor's serial: the boundary is
 * not "another doctor's numbers are safe", it is that the role holds no permission over a number at all, so being
 * on the right desk buys nothing. Every line is a status code from the real route, because the feature is not "the
 * button is hidden" — a hidden button is not a control.
 *
 * Who may put a compounder on a desk in the first place, and the proof that a doctor's own reach was not altered
 * by any of this, close the matrix. The board and the three moves the role DOES have are in CompounderScopeTest.
 */
final class CompounderSerialLockTest extends TestCase
{
    use CompounderFixtures;
    use PrescriptionTestHelpers;

    /** Dr A — a real doctor account, because half these claims need a doctor who signs in. */
    private User $doctorUser;

    private Doctor $mine;

    private Doctor $theirs;

    private SessionInstance $mySession;

    private SessionInstance $theirSession;

    protected function setUp(): void
    {
        parent::setUp();
        $this->asTenant('a');

        $this->doctorUser = $this->actingAsDoctor();
        $this->mine = $this->doctorUser->doctor()->firstOrFail();
        $this->theirs = $this->freshDoctor();
        $this->mySession = $this->openSession(10, 10, 5, $this->mine);
        $this->theirSession = $this->openSession(10, 10, 5, $this->theirs);
    }

    /**
     * CLAIM 5: not one move that hands out, moves, re-numbers or retires a serial is open to a compounder — on the
     * desk they were hired for, with the patient in front of them.
     */
    public function test_no_move_that_changes_a_serial_number_is_open_to_a_compounder_not_even_on_their_own_doctor(): void
    {
        $this->actingAsStaff(Role::Receptionist);
        $booking = $this->book($this->mySession, mobile: '01710000701', name: 'Sufia Begum');
        $serial = $booking->serial;

        $this->actingAsCompounder($this->mine);

        // The number itself: where it sits in the queue, what priority it carries, which session it belongs to.
        $this->postJson($this->url('panel.serials.reorder', ['serial' => $serial->public_id]), ['after' => null])->assertForbidden();
        $this->postJson($this->url('panel.serials.priority', ['serial' => $serial->public_id]), ['priority' => 'emergency'])->assertForbidden();
        $this->postJson($this->url('panel.serials.transfer', ['serial' => $serial->public_id]), ['target_session' => $this->theirSession->public_id])->assertForbidden();

        // Retiring it, or re-issuing it on another day — postpone keeps its own role list precisely so that it did
        // not follow check-in when check-in became a permission the compounder holds.
        $this->postJson($this->url('panel.serials.cancel', ['serial' => $serial->public_id]), ['reason_code' => 'patient_request'])->assertForbidden();
        $this->postJson($this->url('panel.serials.postpone', ['serial' => $serial->public_id]), ['reason' => 'doctor away'])->assertForbidden();

        // Driving the queue is the doctor's, not the desk's: calling, skipping, returning, starting, completing.
        $this->postJson($this->url('panel.serials.call', ['serial' => $serial->public_id]))->assertForbidden();
        $this->postJson($this->url('panel.serials.skip', ['serial' => $serial->public_id]))->assertForbidden();
        $this->postJson($this->url('panel.serials.return', ['serial' => $serial->public_id]))->assertForbidden();
        $this->postJson($this->url('panel.serials.start', ['serial' => $serial->public_id]))->assertForbidden();
        $this->postJson($this->url('panel.serials.complete', ['serial' => $serial->public_id]))->assertForbidden();
        $this->postJson($this->url('panel.sessions.call-next', ['session' => $this->mySession->public_id]))->assertForbidden();

        // Handing OUT a number, by any of its three doors: the engine's own endpoint, the desk's booking dialog,
        // and the kiosk QR — a signed twelve-hour public booking link is issuing serials by another name.
        $this->postJson($this->url('panel.sessions.serials.store', ['session' => $this->mySession->public_id]), ['pool' => 'counter'])->assertForbidden();
        $this->postJson($this->url('panel.reception.bookings.store'), [
            'session' => $this->mySession->public_id, 'mobile' => '01710000702', 'name' => 'Amena Khatun', 'channel' => 'counter',
        ])->assertForbidden();
        $this->getJson($this->url('panel.reception.kiosk_url', ['session' => $this->mySession->public_id]))->assertForbidden();

        // The booking above the number: cancelling it or moving it to another session re-writes the same row.
        $this->postJson($this->url('panel.reception.appointments.cancel', ['appointment' => $booking->appointment->public_id]), ['reason_code' => 'patient_request'])->assertForbidden();
        $this->postJson($this->url('panel.reception.appointments.reschedule', ['appointment' => $booking->appointment->public_id]), ['target_session' => $this->theirSession->public_id])->assertForbidden();

        // And the session-level dials that decide how many numbers exist at all.
        $this->postJson($this->url('panel.sessions.extend', ['session' => $this->mySession->public_id]), ['by' => 2])->assertForbidden();
        $this->putJson($this->url('panel.sessions.pools.split', ['session' => $this->mySession->public_id]), ['counter_quota' => 12, 'online_quota' => 8])->assertForbidden();
        $this->postJson($this->url('panel.sessions.pools.release-online', ['session' => $this->mySession->public_id]))->assertForbidden();

        // Nothing moved. The proof that every refusal above was a refusal and not a silent no-op.
        $serial->refresh();
        $this->assertSame('booked', $serial->status->value);
        $this->assertSame($this->mySession->id, $serial->session_instance_id);
        $this->assertSame(1, $serial->number);
    }

    /**
     * CLAIM 6: the desk reads and prints, it does not prescribe — and it reads only its own doctor. The print path
     * is the one that must survive: BRIEF §5.G.4 has the sheet handed over at the desk on the way out.
     */
    public function test_the_writer_is_shut_a_colleagues_sheet_is_invisible_and_the_desk_print_survives(): void
    {
        $patient = Patient::factory()->create(['name' => 'Momena Begum']);

        // My doctor's issued sheet, written and issued by the doctor themselves through the real actions.
        $this->actingAs($this->doctorUser, 'web');
        $myVisit = Visit::factory()->create(['patient_id' => $patient->id, 'doctor_id' => $this->mine->id]);
        $draft = $this->draftFor($myVisit, $this->mine);
        $this->savedDraft($draft, $this->itemsPayload([['slug' => 'paracetamol', 'shorthand' => '1+0+1 5d af', 'label' => '500 mg']]));
        $issued = $this->issued($draft->fresh());

        // The colleague's sheet for the SAME patient: a patient-level scope alone would hand this one over, which
        // is why the prescription surfaces ask for the doctor and not only for the patient.
        $theirVisit = Visit::factory()->create(['patient_id' => $patient->id, 'doctor_id' => $this->theirs->id]);
        $theirs = Prescription::factory()->create(['visit_id' => $theirVisit->id]);

        $this->actingAsCompounder($this->mine);

        // The writer is shut even on their own doctor's encounter: prescribing is not a desk job.
        $this->get($this->url('panel.prescription.writer', ['visit' => $myVisit->public_id]))->assertForbidden();
        $this->postJson($this->url('panel.prescription.drafts.store', ['visit' => $myVisit->public_id]))->assertForbidden();
        $this->postJson($this->url('panel.prescription.prescriptions.issue', ['prescription' => $issued->public_id]))->assertForbidden();
        $this->patchJson($this->url('panel.prescription.prescriptions.draft', ['prescription' => $issued->public_id]), [])->assertForbidden();

        // The colleague's sheet does not exist for this desk — not the record, not the paper.
        $this->getJson($this->url('panel.prescription.prescriptions.show', ['prescription' => $theirs->public_id]))->assertForbidden();
        $this->get($this->url('panel.prescription.print', ['prescription' => $theirs->public_id]))->assertForbidden();

        // Their own doctor's sheet prints, which is the point of the feature.
        $this->get($this->url('panel.prescription.print', ['prescription' => $issued->public_id]))->assertOk()->assertSee($patient->name, false);
        $this->get($this->url('panel.prescription.pharmacy', ['prescription' => $issued->public_id]))->assertOk();
        $this->getJson($this->url('panel.prescription.prescriptions.show', ['prescription' => $issued->public_id]))->assertOk();

        // Reading a sheet at the desk is not speaking to the patient in the doctor's name.
        $this->postJson($this->url('panel.prescription.prescriptions.send', ['prescription' => $issued->public_id]), ['channel' => 'sms'])->assertForbidden();

        // The list is the same rule applied to many rows: one sheet, and the doctor filter offers one name.
        $this->get($this->url('panel.prescriptions.index'))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('Prescription/Index')
                ->has('prescriptions.data', 1)
                ->where('prescriptions.data.0.id', $issued->public_id)
                ->has('options.doctors', 1)
                ->where('options.doctors.0.public_id', $this->mine->public_id));
    }

    /**
     * CLAIM 9: a desk is staffed by the doctor who will be working next to that person, or by the hospital admin.
     * Not by a colleague, and not by the front desk. (The screen's own props are pinned in CompounderAssignmentTest;
     * what this asserts is the authority.)
     */
    public function test_only_the_doctor_themselves_or_a_hospital_admin_may_staff_a_desk(): void
    {
        $compounder = User::factory()->withRole(Role::Compounder)->create();

        // The doctor administers their own row — the same shape as designing their own pad.
        $this->actingAs($this->doctorUser, 'web');
        $this->get($this->url('panel.clinic.doctors.compounders.index', ['doctor' => $this->mine->public_id]))->assertOk();
        $this->post($this->url('panel.clinic.doctors.compounders.store', ['doctor' => $this->mine->public_id]), ['user_public_id' => $compounder->public_id])->assertRedirect();

        // ...and only their own. A doctor may not put staff on a colleague's desk, which would be granting access
        // to a patient list that is not theirs to grant.
        $this->get($this->url('panel.clinic.doctors.compounders.index', ['doctor' => $this->theirs->public_id]))->assertForbidden();
        $this->post($this->url('panel.clinic.doctors.compounders.store', ['doctor' => $this->theirs->public_id]), ['user_public_id' => $compounder->public_id])->assertForbidden();
        $this->assertDatabaseMissing('doctor_compounder', ['doctor_id' => $this->theirs->id, 'user_id' => $compounder->id]);

        // The front desk books patients; it does not decide who may read a doctor's patients.
        $this->actingAsStaff(Role::Receptionist);
        $this->get($this->url('panel.clinic.doctors.compounders.index', ['doctor' => $this->mine->public_id]))->assertForbidden();
        $this->post($this->url('panel.clinic.doctors.compounders.store', ['doctor' => $this->mine->public_id]), ['user_public_id' => $compounder->public_id])->assertForbidden();
        $this->delete($this->url('panel.clinic.doctors.compounders.destroy', ['doctor' => $this->mine->public_id, 'compounder' => $compounder->public_id]))->assertForbidden();

        // The hospital admin staffs any desk and clears it again.
        $this->actingAsStaff(Role::HospitalAdmin);
        $this->post($this->url('panel.clinic.doctors.compounders.store', ['doctor' => $this->theirs->public_id]), ['user_public_id' => $compounder->public_id])->assertRedirect();
        $this->assertDatabaseHas('doctor_compounder', ['doctor_id' => $this->theirs->id, 'user_id' => $compounder->id]);
        $this->delete($this->url('panel.clinic.doctors.compounders.destroy', ['doctor' => $this->theirs->public_id, 'compounder' => $compounder->public_id]))->assertRedirect();
        $this->assertDatabaseMissing('doctor_compounder', ['doctor_id' => $this->theirs->id, 'user_id' => $compounder->id]);
    }

    /**
     * CLAIM 10: the doctor's own reach is what it was. DoctorScope answers `null` for a doctor, so every rule added
     * for the compounder is a conjunct that was already true for them — this is the test that says so out loud.
     */
    public function test_a_doctors_own_reach_is_what_it_was(): void
    {
        $this->actingAsStaff(Role::Receptionist);
        $mineSerial = $this->serialOn($this->mySession, '01710000711', 'Rashida Begum');
        $theirSerial = $this->serialOn($this->theirSession, '01710000712', 'Shahnaz Parvin');

        $this->actingAs($this->doctorUser, 'web');

        // The whole branch's board, not just their own chamber: a doctor was never scoped to their own sessions.
        $sessions = $this->polledSessions();
        $this->assertCount(2, $sessions);

        // Arrival is a permission the doctor holds clinic-wide, exactly as it was when it was a role list.
        $this->postJson($this->url('panel.serials.check-in', ['serial' => $mineSerial->public_id]))->assertOk();
        $this->postJson($this->url('panel.serials.check-in', ['serial' => $theirSerial->public_id]))->assertOk();

        // Their own queue is still theirs to drive, and the colleague's still is not (ChannelGuards::doctor's rule).
        $this->postJson($this->url('panel.sessions.call-next', ['session' => $this->mySession->public_id]))->assertOk();
        $this->postJson($this->url('panel.sessions.call-next', ['session' => $this->theirSession->public_id]))->assertForbidden();

        // And the boundary a doctor always had: they write on their own encounters and open their own patients.
        // (Reading a colleague's VISIT stays open to anyone holding prescriptions.vitals.record, a doctor included
        // — that is how it read before this feature, and DoctorScope answering `null` for them is what keeps it so.
        // The line that moved is the compounder's, not theirs.)
        $minePatient = Patient::factory()->create();
        $theirPatient = Patient::factory()->create();
        $mineVisit = Visit::factory()->create(['patient_id' => $minePatient->id, 'doctor_id' => $this->mine->id]);
        $theirVisit = Visit::factory()->create(['patient_id' => $theirPatient->id, 'doctor_id' => $this->theirs->id]);

        $this->get($this->url('panel.prescription.writer', ['visit' => $mineVisit->public_id]))->assertOk();
        $this->get($this->url('panel.prescription.writer', ['visit' => $theirVisit->public_id]))->assertForbidden();
        $this->get($this->url('panel.patients.show', ['patient' => $minePatient->public_id]))->assertOk();
        $this->get($this->url('panel.patients.show', ['patient' => $theirPatient->public_id]))->assertForbidden();
    }

    /**
     * CLAIM 5, the escape hatch: holding the compounder role ALWAYS restricts, whatever else the same account holds.
     *
     * A compounder promoted to cover the front desk — or a receptionist handed a doctor's desk — carries BOTH roles,
     * and every permission the serial engine asks for then answers yes: `serials.cancel`, `serials.reorder`,
     * `serials.transfer`, and the receptionist row of postpone's own list. The first cut of this feature put
     * DoctorScope on `view`, `recordVitals` and `checkIn` only and left the number-editing moves on their permission
     * alone, so this one account read a board narrowed to Dr A and could then cancel, postpone, re-order,
     * re-prioritise or transfer any row on Dr B's list — by public id, from the same session, with the full
     * resource (patient block included) coming back. Every ability on SerialPolicy carries the conjunct now.
     *
     * Both halves are asserted on purpose: the same five moves SUCCEED on their own doctor's row. Without that, a
     * policy that simply refused everything would pass this test and the desk would be broken instead of bounded.
     */
    public function test_an_account_holding_both_compounder_and_receptionist_is_still_bounded_by_its_assignment(): void
    {
        $this->actingAsStaff(Role::Receptionist);
        // A later session for the same doctor: postpone re-issues the number on it, and transfer needs a target that
        // belongs to the same doctor — neither move can be proved to be ALLOWED without somewhere to land.
        $evening = SessionInstance::factory()->on($this->today(), 'E', '17:00', '21:00')->quotas(10, 10, 5)
            ->create(['doctor_id' => $this->mine->id, 'branch_id' => $this->mainBranch()->id]);

        $reorder = $this->allocate($this->mySession);
        $priority = $this->allocate($this->mySession);
        $transfer = $this->allocate($this->mySession);
        $postpone = $this->allocate($this->mySession);
        $cancel = $this->allocate($this->mySession);
        $theirs = $this->allocate($this->theirSession);

        /** @var User $user */
        $user = $this->actingAsStaff(Role::Receptionist);
        $user->assignRole(Role::Compounder->value);
        app(AssignCompounder::class)->handle($this->mine, $user, Actor::system());

        // The board is the receptionist's whole branch no longer: the compounder role restricts, and the account
        // therefore reads one session — which is what makes the five refusals below cross-doctor and not cross-branch.
        $doctorsOnTheBoard = array_column(array_column($this->polledSessions(), 'doctor'), 'public_id');
        $this->assertSame([$this->mine->public_id], array_values(array_unique($doctorsOnTheBoard)), 'the compounder role narrows even an account that also staffs the front desk');
        $this->assertTrue($user->hasRole(Role::Receptionist->value), 'the receptionist role is still held — these are permissions, not a demotion');

        // Their own doctor's rows answer to every one of the five, so the refusals are about WHOSE row it is.
        $this->postJson($this->url('panel.serials.reorder', ['serial' => $reorder->public_id]), ['after' => $priority->public_id])->assertOk();
        $this->postJson($this->url('panel.serials.priority', ['serial' => $priority->public_id]), ['priority' => 'emergency', 'reason' => 'collapsed at the door'])->assertOk();
        // Transfer is the one positive that cannot be a round trip: TransferSerial refuses a same-doctor target
        // ("same-doctor moves are postpones"), so proving it over HTTP would mean moving this row into a chamber this
        // account is NOT scoped to — a separate question, and not one this test should answer in passing. The ability
        // itself is asserted instead, which is all the line below needs as its control.
        $this->assertTrue(Gate::forUser($user)->allows('transfer', $transfer), 'the receptionist role still carries serials.transfer on their own doctor\'s row');
        $this->postJson($this->url('panel.serials.postpone', ['serial' => $postpone->public_id]), ['reason' => 'doctor away'])->assertOk();
        $this->postJson($this->url('panel.serials.cancel', ['serial' => $cancel->public_id]), ['reason_code' => 'patient_request'])->assertOk();

        // The colleague's row: the same account, the same session, the same five URLs, every one refused.
        $this->postJson($this->url('panel.serials.cancel', ['serial' => $theirs->public_id]), ['reason_code' => 'patient_request'])->assertForbidden();
        $this->postJson($this->url('panel.serials.postpone', ['serial' => $theirs->public_id]), ['reason' => 'doctor away'])->assertForbidden();
        $this->postJson($this->url('panel.serials.reorder', ['serial' => $theirs->public_id]), ['after' => null])->assertForbidden();
        $this->postJson($this->url('panel.serials.priority', ['serial' => $theirs->public_id]), ['priority' => 'emergency', 'reason' => 'chest pain'])->assertForbidden();
        $this->postJson($this->url('panel.serials.transfer', ['serial' => $theirs->public_id]), ['target_session' => $evening->public_id])->assertForbidden();

        // …and the queue-driving moves, which the receptionist role also carries through `queue.call-next`.
        $this->postJson($this->url('panel.serials.call', ['serial' => $theirs->public_id]))->assertForbidden();
        $this->postJson($this->url('panel.serials.complete', ['serial' => $theirs->public_id]))->assertForbidden();
        $this->postJson($this->url('panel.sessions.call-next', ['session' => $this->theirSession->public_id]))->assertForbidden();

        // Nothing moved on the colleague's row, and the refusal did not leak the row either.
        $theirs->refresh();
        $this->assertSame('booked', $theirs->status->value);
        $this->assertSame($this->theirSession->id, $theirs->session_instance_id);
        $this->assertSame(1, $theirs->number);
    }
}
