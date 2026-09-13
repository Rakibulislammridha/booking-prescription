<?php

declare(strict_types=1);

namespace Tests\Feature\Clinic;

use App\Domain\Clinic\Actions\AssignCompounder;
use App\Domain\Clinic\Actions\UnassignCompounder;
use App\Domain\Clinic\Enums\Role;
use App\Domain\Serials\Actions\CheckInSerial;
use App\Domain\Serials\Actions\PriorityInsert;
use App\Domain\Serials\Enums\SerialPriority;
use App\Domain\Shared\Actor;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\Patient;
use App\Models\Tenant\SessionInstance;
use App\Models\Tenant\Visit;
use App\Models\Tenant\Vital;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\Clinic\Concerns\CompounderFixtures;
use Tests\TestCase;

/**
 * CLAIM: a compounder works one desk — their own doctor's. The board they open, the JSON that board polls, the
 * three moves they may make on a row (arrived, vitals, fee) and the encounters behind them all answer for the
 * doctor they were assigned to and refuse for the doctor across the corridor, and the answer is re-read on every
 * single request so that taking the assignment away is felt immediately.
 *
 * Every assertion goes through the real HTTP routes with a real session. A policy asked directly proves the policy;
 * it does not prove that the URL the desk actually opens ever consults it, which is the only thing a boundary is.
 *
 * The serial-number moves a compounder must NOT have, the prescription surfaces and who may staff a desk are the
 * other half of this matrix and live in CompounderSerialLockTest.
 */
final class CompounderScopeTest extends TestCase
{
    use CompounderFixtures;

    /** The doctor who hired the compounder. */
    private Doctor $mine;

    /** The colleague across the corridor — the control in every case below. */
    private Doctor $theirs;

    private SessionInstance $mySession;

    private SessionInstance $theirSession;

    protected function setUp(): void
    {
        parent::setUp();
        $this->asTenant('a');

        // The day is opened and the patients are booked by the desk that is allowed to do it; the compounder signs
        // in afterwards, which is also the real sequence at a clinic.
        $this->actingAsStaff(Role::Receptionist);
        $this->mine = $this->freshDoctor();
        $this->theirs = $this->freshDoctor();
        $this->mySession = $this->openSession(10, 10, 5, $this->mine);
        $this->theirSession = $this->openSession(10, 10, 5, $this->theirs);
    }

    /**
     * CLAIM 1 + 2: the page and the five-second poll are the same document and are narrowed the same way, and
     * narrowing never re-sorts — the rows stay in serial-number order, "serials will be in an ordered list".
     */
    public function test_the_board_is_narrowed_to_the_assigned_doctor_in_both_the_page_and_the_poll(): void
    {
        [$s1, $s2, $s3, $s4] = array_map(
            fn () => $this->allocate($this->mySession, patientId: Patient::factory()->create()->id),
            range(1, 4),
        );
        $notMine = $this->allocate($this->theirSession, patientId: Patient::factory()->create()->id);

        // The queue is deliberately put out of step with the numbering before anyone looks, the way it really goes
        // out of step: arriving does not move a row, but the last patient collapses at the door and is inserted at
        // the head of the queue. The engine's `position` now runs 4, 1, 2, 3 while the numbers still run 1, 2, 3, 4
        // — and the difference between "filtered" and "re-sorted" would be invisible if the two orders agreed.
        app(CheckInSerial::class)->handle($s4, $this->staffActor());
        app(PriorityInsert::class)->handle($s4->fresh(), SerialPriority::Emergency, $this->staffActor(), 'collapsed at the door');
        app(CheckInSerial::class)->handle($s2, $this->staffActor());

        $this->actingAsCompounder($this->mine);

        $this->get($this->url('panel.reception.board'))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('Reception/Board')
                ->has('board.sessions', 1)
                ->where('board.sessions.0.public_id', $this->mySession->public_id)
                ->where('board.sessions.0.doctor.public_id', $this->mine->public_id)
                ->where('board.sessions.0.serials.0.display_code', $s1->display_code)
                ->where('board.sessions.0.serials.1.display_code', $s2->display_code)
                ->where('board.sessions.0.serials.2.display_code', $s3->display_code)
                ->where('board.sessions.0.serials.3.display_code', $s4->display_code)
                // The desk's own three moves are offered; everything that edits a number is not.
                ->where('can.check_in', true)
                ->where('can.record_vitals', true)
                ->where('can.collect', true)
                ->where('can.issue', false)
                ->where('can.call_next', false)
                ->where('can.cancel', false)
                ->where('can.kiosk', false));

        // The desk reads the JSON every five seconds. A filter applied to the page and not to the poll is not a
        // filter — four seconds later the colleague's session would appear in the same screen.
        $sessions = $this->polledSessions();
        $this->assertCount(1, $sessions);
        $this->assertSame($this->mySession->public_id, $sessions[0]['public_id']);
        $this->assertSame(
            [$s1->display_code, $s2->display_code, $s3->display_code, $s4->display_code],
            $this->codesOf($sessions, $this->mySession->public_id),
            'a scoped board is narrowed, never re-sorted',
        );
        $this->assertSame([1, 2, 3, 4], array_column($sessions[0]['serials'], 'number'));

        /** @var array<int, array<string, mixed>> $rows */
        $rows = $sessions[0]['serials'];
        $this->assertLessThan($rows[0]['position'], $rows[3]['position'], 'the queue really is out of step with the numbering, or the line above proves nothing');

        // The colleague's row is not merely unlisted under another heading: it is nowhere in the document.
        $this->getJson($this->url('panel.reception.board.data'))->assertOk()
            ->assertJsonMissing(['public_id' => $this->theirSession->public_id])
            ->assertJsonMissing(['public_id' => $notMine->public_id]);

        // The waiting-room view of the same day is a second board with a second poll behind it, and the map of
        // doctors its rows link through is a third query — all three narrow, or the colleague is back by another door.
        $this->get($this->url('panel.queue.today'))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('Queue/Today')->has('board.sessions', 1)->has('doctors', 1));
        $this->getJson($this->url('panel.queue.today.data'))->assertOk()
            ->assertJsonCount(1, 'board.sessions')
            ->assertJsonCount(1, 'doctors')
            ->assertJsonMissing(['public_id' => $this->theirSession->public_id]);
    }

    /**
     * CLAIM 3: "the compounder will assign the patient's vitals, fee, and arrived-or-not status" — all three, for
     * their own doctor, through the screens the desk actually uses.
     */
    public function test_the_desk_may_mark_arrived_record_the_vitals_and_take_the_fee_for_its_own_doctor(): void
    {
        $appointment = $this->book($this->mySession, mobile: '01710000601', name: 'Nasima Akter')->appointment;
        $serial = $this->serialOn($this->mySession, '01710000602', 'Jahanara Khatun');

        $compounder = $this->actingAsCompounder($this->mine);

        // arrived-or-not status
        $this->postJson($this->url('panel.serials.check-in', ['serial' => $serial->public_id]))->assertOk()
            ->assertJsonPath('serial.status', 'checked_in');

        // vitals: the board row opens the encounter, the entry screen renders, the reading is written through the
        // Prescription module's own endpoint (there is no second write path).
        $this->post($this->url('panel.reception.vitals.open', ['serial' => $serial->public_id]))->assertRedirect();
        $visit = Visit::query()->where('serial_id', $serial->id)->firstOrFail();
        $this->get($this->url('panel.reception.vitals.edit', ['visit' => $visit->public_id]))->assertOk();

        $this->postJson($this->url('panel.prescription.vitals.store', ['visit' => $visit->public_id]), [
            'pulse_bpm' => 82, 'bp_systolic' => 120, 'bp_diastolic' => 80, 'temperature_f' => 100.4,
        ])->assertCreated()
            ->assertJsonPath('vitals.temperature_f', 100.4)
            ->assertJsonPath('vitals.recorded_by.name', $compounder->name);

        // °F at the human boundary, °C on the wire (100.4 °F = 38.0 °C exactly, so the round trip is lossless).
        $vital = Vital::query()->where('visit_id', $visit->id)->latest('id')->firstOrFail();
        $this->assertSame(38.0, (float) $vital->temperature_c);

        // Correcting a reading they just took is the same ability on the same visit, not a doctor's edit.
        $this->patchJson($this->url('panel.prescription.vitals.update', ['vital' => $vital->id]), ['pulse_bpm' => 88])
            ->assertOk()->assertJsonPath('vitals.pulse_bpm', 88);

        // fee
        $this->postJson($this->url('panel.reception.appointments.collect', ['appointment' => $appointment->public_id]), [])
            ->assertOk()->assertJsonPath('payment.payment_status', 'paid');

        // ...and the bill it raised stays legible at the desk, which is the whole reason the role holds
        // billing.invoices.view — including the outstanding-balance seam the board shows beside the fee button.
        $this->get($this->url('panel.billing.invoices.show', ['invoice' => $this->issuedInvoiceFor($appointment)->public_id]))->assertOk();
        $this->getJson($this->url('panel.billing.patients.dues', ['patient' => $appointment->patient->public_id]))->assertOk()
            ->assertJsonPath('patient.public_id', $appointment->patient->public_id);
    }

    /** CLAIM 4: the same three moves, the same compounder, a patient of the doctor they do not work for. */
    public function test_none_of_those_three_moves_reaches_a_colleagues_patient(): void
    {
        $appointment = $this->book($this->theirSession, mobile: '01710000611', name: 'Shirin Sultana')->appointment;
        $serial = $this->serialOn($this->theirSession, '01710000612', 'Morjina Begum');

        // The colleague's patient is genuinely in the building and genuinely has an open encounter: the refusals
        // below are about WHOSE patient this is, not about the row being in the wrong state.
        app(CheckInSerial::class)->handle($serial, $this->staffActor());
        $theirVisit = Visit::factory()->create(['patient_id' => $serial->patient_id, 'doctor_id' => $this->theirs->id]);

        $this->actingAsCompounder($this->mine);

        $this->postJson($this->url('panel.serials.check-in', ['serial' => $serial->public_id]))->assertForbidden();
        $this->postJson($this->url('panel.reception.vitals.open', ['serial' => $serial->public_id]))->assertForbidden();
        $this->postJson($this->url('panel.reception.appointments.collect', ['appointment' => $appointment->public_id]), [])->assertForbidden();

        // Nor by the back doors of the same three moves: the entry screen, the vitals write, the encounter itself
        // and the session document that lists every one of the colleague's rows.
        $this->get($this->url('panel.reception.vitals.edit', ['visit' => $theirVisit->public_id]))->assertForbidden();
        $this->postJson($this->url('panel.prescription.vitals.store', ['visit' => $theirVisit->public_id]), ['pulse_bpm' => 80])->assertForbidden();
        $this->getJson($this->url('panel.prescription.visits.show', ['visit' => $theirVisit->public_id]))->assertForbidden();
        $this->getJson($this->url('panel.sessions.show', ['session' => $this->theirSession->public_id]))->assertForbidden();

        // The dues seam names the patient in its body, so a scoped balance is not enough on its own: a zero due
        // attached to a colleague's patient's NAME is still that patient's name.
        $this->getJson($this->url('panel.billing.patients.dues', ['patient' => $appointment->patient->public_id]))->assertForbidden();

        // A reading the colleague's desk already took is not theirs to edit either — PATCH names a vitals row, not
        // a visit, so it is the one write that could have slipped the scope.
        $theirVital = Vital::factory()->for($theirVisit)->create();
        $this->patchJson($this->url('panel.prescription.vitals.update', ['vital' => $theirVital->id]), ['pulse_bpm' => 70])->assertForbidden();
    }

    /** CLAIM 7: no assignment is no desk — an empty list is a deny everywhere, never "everything". */
    public function test_a_compounder_on_nobodys_desk_sees_nothing_and_may_do_nothing(): void
    {
        $appointment = $this->book($this->mySession, mobile: '01710000621', name: 'Rokeya Begum')->appointment;
        $serial = $this->serialOn($this->mySession, '01710000622', 'Hasina Akter');

        $this->actingAsStaff(Role::Compounder);   // hired, given the role, put on no desk yet

        // The door opens — being at the desk is the role — but the room is empty.
        $this->get($this->url('panel.reception.board'))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('Reception/Board')->has('board.sessions', 0));
        $this->assertSame([], $this->polledSessions());

        $this->postJson($this->url('panel.serials.check-in', ['serial' => $serial->public_id]))->assertForbidden();
        $this->postJson($this->url('panel.reception.vitals.open', ['serial' => $serial->public_id]))->assertForbidden();
        $this->postJson($this->url('panel.reception.appointments.collect', ['appointment' => $appointment->public_id]), [])->assertForbidden();
        $this->get($this->url('panel.prescriptions.index'))->assertForbidden();
    }

    /**
     * CLAIM 8: nothing is memoised. Same browser, same cookie, next request — the assignment is gone, and so is
     * everything it granted. This codebase has already been bitten by state checked once and never re-checked.
     */
    public function test_revoking_the_assignment_and_deactivating_the_account_both_bite_on_the_next_request(): void
    {
        $serial = $this->serialOn($this->mySession, '01710000631', 'Anwara Khatun');
        $compounder = $this->actingAsCompounder($this->mine);

        $this->assertCount(1, $this->polledSessions());
        $this->postJson($this->url('panel.serials.check-in', ['serial' => $serial->public_id]))->assertOk();

        app(UnassignCompounder::class)->handle($this->mine, $compounder, Actor::system());

        $this->assertSame([], $this->polledSessions(), 'a revoked desk must be empty on the very next request');
        $this->postJson($this->url('panel.serials.no-show', ['serial' => $serial->public_id]))->assertForbidden();
        $this->get($this->url('panel.prescriptions.index'))->assertForbidden();

        // Put them back — the grant is re-read too, not just the revocation.
        app(AssignCompounder::class)->handle($this->mine, $compounder, Actor::system());
        $this->assertCount(1, $this->polledSessions());

        // Now take the account itself away. EnsureStaffIsActive signs them out on the next request, so this is a
        // redirect to the login screen rather than a 403: they are not refused the board, they are not staff.
        $compounder->forceFill(['is_active' => false])->save();
        $this->get($this->url('panel.reception.board'))->assertRedirect(route('panel.login', absolute: false));
        $this->getJson($this->url('panel.reception.board.data'))->assertUnauthorized();
        $this->assertGuest('web');
    }

    /**
     * CLAIM 10: the roles that were here first are untouched. The compounder's rules are additional conjuncts on
     * paths a receptionist already satisfied (they hold serials.check-in, and DoctorScope answers `null` for them),
     * so the desk they have worked at for a year must behave exactly as it did.
     */
    public function test_the_receptionists_desk_is_exactly_what_it_was(): void
    {
        $mineAppointment = $this->book($this->mySession, mobile: '01710000641', name: 'Salma Begum')->appointment;
        $theirAppointment = $this->book($this->theirSession, mobile: '01710000642', name: 'Rahela Khatun')->appointment;
        $mineSerial = $this->serialOn($this->mySession, '01710000643', 'Fatema Begum');
        $theirSerial = $this->serialOn($this->theirSession, '01710000644', 'Ayesha Siddika');

        $this->actingAsStaff(Role::Receptionist);

        // The whole branch, both doctors, and both sessions still carry their rows.
        $sessions = $this->polledSessions();
        $this->assertCount(2, $sessions);
        $this->assertEqualsCanonicalizing(
            [$this->mySession->public_id, $this->theirSession->public_id],
            array_column($sessions, 'public_id'),
        );

        foreach ([$mineSerial, $theirSerial] as $serial) {
            $this->postJson($this->url('panel.serials.check-in', ['serial' => $serial->public_id]))->assertOk();
            $this->post($this->url('panel.reception.vitals.open', ['serial' => $serial->public_id]))->assertRedirect();

            $visit = Visit::query()->where('serial_id', $serial->id)->firstOrFail();
            $this->postJson($this->url('panel.prescription.vitals.store', ['visit' => $visit->public_id]), ['pulse_bpm' => 76])->assertCreated();
        }

        foreach ([$mineAppointment, $theirAppointment] as $appointment) {
            $this->postJson($this->url('panel.reception.appointments.collect', ['appointment' => $appointment->public_id]), [])
                ->assertOk()->assertJsonPath('payment.payment_status', 'paid');
        }

        // The dues seam still answers for every patient in the building, which is what it did before the scope.
        foreach ([$mineAppointment, $theirAppointment] as $appointment) {
            $this->getJson($this->url('panel.billing.patients.dues', ['patient' => $appointment->patient->public_id]))->assertOk();
        }

        // And the counter still issues numbers and still mints the kiosk link, the two doors the compounder is
        // kept away from.
        $this->getJson($this->url('panel.reception.kiosk_url', ['session' => $this->mySession->public_id]))->assertOk();
        $this->postJson($this->url('panel.reception.bookings.store'), [
            'session' => $this->theirSession->public_id, 'mobile' => '01710000645', 'name' => 'Nurjahan Begum', 'channel' => 'counter',
        ])->assertCreated();
    }
}
