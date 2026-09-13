<?php

declare(strict_types=1);

namespace Tests\Feature\Reception;

use App\Domain\Clinic\Enums\Role;
use App\Domain\Serials\Actions\CheckInSerial;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\Patient;
use App\Models\Tenant\Prescription;
use App\Models\Tenant\Serial;
use App\Models\Tenant\SessionInstance;
use App\Models\Tenant\User;
use App\Models\Tenant\Visit;
use Illuminate\Support\Facades\Gate;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\Billing\Concerns\BillingFixtures;
use Tests\TestCase;

/**
 * The compounder boundary as the product owner stated it: "he can manage those specific doctor's patients; he
 * can't be able to see other doctors' patient lists ... he can't be able to edit the serial number, and serials
 * will be in an ordered list."
 *
 * Every assertion here is server-side and reached over HTTP or through the Gate, because a hidden button is not a
 * control: the same compounder, the same URL, two doctors — one answers, the other 403s. The ordering half is
 * proved ON THE FILTERED BOARD, with the queue deliberately out of step with the numbers, so that narrowing the
 * board can never be mistaken for re-sorting it.
 */
final class CompounderDeskScopeTest extends TestCase
{
    use BillingFixtures;

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

    /** A doctor with no weekly template: the board must hold exactly the sessions this test created, nothing materialised. */
    private function newDoctor(): Doctor
    {
        return Doctor::factory()->complete()->create();
    }

    /** A compounder signed in at the main branch, on the desks of the given doctors. */
    private function actingAsCompounder(Doctor ...$doctors): User
    {
        $user = $this->actingAsStaff(Role::Compounder);
        $user->assignedDoctors()->sync(array_map(fn (Doctor $d) => $d->id, $doctors));

        return $user;
    }

    /** Booked by the desk (BillingFixtures::book bills the signed-in user as the actor, so sign in first). */
    private function serialOf(SessionInstance $session, string $mobile, string $name): Serial
    {
        return Serial::query()->findOrFail($this->book($session, mobile: $mobile, name: $name)->appointment->serial_id);
    }

    /** @return array<int, string> the display codes of a board session, in the order the board listed them */
    private function codes(string $sessionPublicId): array
    {
        /** @var array{sessions: array<int, array<string, mixed>>} $json */
        $json = $this->getJson($this->url('panel.reception.board.data'))->assertOk()->json();
        /** @var array<string, mixed> $row */
        $row = collect($json['sessions'])->firstWhere('public_id', $sessionPublicId);

        /** @var array<int, array<string, mixed>> $serials */
        $serials = $row['serials'];

        return array_map(fn (array $s) => (string) $s['display_code'], $serials);
    }

    public function test_the_board_holds_only_the_assigned_doctors_sessions_and_still_lists_them_by_number(): void
    {
        $this->actingAsStaff(Role::Receptionist);
        $mine = $this->openSession(10, 10, 5, $this->newDoctor());
        $theirs = $this->openSession(10, 10, 5, $this->newDoctor());

        [$s1, $s2, $s3, $s4] = array_map(fn () => $this->allocate($mine, patientId: Patient::factory()->create()->id), range(1, 4));
        $this->allocate($theirs, patientId: Patient::factory()->create()->id);

        // The queue is deliberately not the numbering: 3 arrives, then 2.
        app(CheckInSerial::class)->handle($s3, $this->staffActor());
        app(CheckInSerial::class)->handle($s2, $this->staffActor());

        $this->actingAsCompounder($this->doctorOf($mine));

        $this->get($this->url('panel.reception.board'))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('Reception/Board')
                ->has('board.sessions', 1)
                ->where('board.sessions.0.public_id', $mine->public_id)
                ->where('board.sessions.0.doctor.public_id', $this->doctorOf($mine)->public_id)
                // The compounder's desk is the one the `doctor_scoped` flag exists for: the page keeps to the
                // scoped JSON and never opens the registered tablet's Dexie cache, which is a whole branch.
                ->where('doctor_scoped', true)
                ->where('can.check_in', true)
                ->where('can.record_vitals', true)
                // The quick-search would 403 for them (PatientLookupController authorises `viewAny` on Patient)
                // and the dues panel beside it has no other input, so the desk is not offered either.
                ->where('can.search_patients', false)
                ->where('can.collect', true)
                ->where('can.issue', false)
                ->where('can.call_next', false)
                ->where('can.cancel', false)
                ->where('can.kiosk', false));

        // The polled JSON is the same document and is filtered the same way — and the rows are B-001..B-004 by
        // number even though the queue is 3, 2.
        $this->assertSame(
            [$s1->display_code, $s2->display_code, $s3->display_code, $s4->display_code],
            $this->codes($mine->public_id),
            'a scoped board is narrowed, never re-sorted',
        );

        /** @var array{sessions: array<int, array<string, mixed>>} $json */
        $json = $this->getJson($this->url('panel.reception.board.data'))->assertOk()->json();
        $this->assertCount(1, $json['sessions'], 'the 5 s poll is filtered too, or the page filter is theatre');

        // A receptionist at the same desk still sees the whole branch.
        $this->actingAsStaff(Role::Receptionist);
        $this->getJson($this->url('panel.reception.board.data'))->assertOk()->assertJsonCount(2, 'sessions');
    }

    public function test_the_arrival_desk_works_for_their_doctor_and_403s_for_another(): void
    {
        $this->actingAsStaff(Role::Receptionist);
        $mine = $this->openSession(10, 10, 5, $this->newDoctor());
        $theirs = $this->openSession(10, 10, 5, $this->newDoctor());
        $ours = $this->serialOf($mine, '01710000501', 'Mine');
        $others = $this->serialOf($theirs, '01710000502', 'Theirs');

        $this->actingAsCompounder($this->doctorOf($mine));

        $this->post($this->url('panel.serials.check-in', ['serial' => $ours->public_id]))->assertOk();
        $this->post($this->url('panel.serials.check-in', ['serial' => $others->public_id]))->assertForbidden();

        // ...and the whole of the rest of the serial engine stays shut: no number of theirs, and no number of
        // anybody's, may be moved, issued, cancelled or called by a compounder.
        $this->post($this->url('panel.serials.reorder', ['serial' => $ours->public_id]), ['after' => null])->assertForbidden();
        $this->post($this->url('panel.serials.priority', ['serial' => $ours->public_id]), ['priority' => 'emergency'])->assertForbidden();
        $this->post($this->url('panel.serials.cancel', ['serial' => $ours->public_id]))->assertForbidden();
        $this->post($this->url('panel.serials.transfer', ['serial' => $ours->public_id]))->assertForbidden();
        $this->post($this->url('panel.serials.call', ['serial' => $ours->public_id]))->assertForbidden();
        $this->post($this->url('panel.sessions.serials.store', ['session' => $mine->public_id]))->assertForbidden();
        $this->post($this->url('panel.sessions.call-next', ['session' => $mine->public_id]))->assertForbidden();

        // Postpone re-issues a number on another day: it kept its role list when check-in became a permission.
        $this->post($this->url('panel.serials.postpone', ['serial' => $ours->public_id]), ['reason' => 'doctor away'])->assertForbidden();

        // No-show and reinstate are the same ability and the same boundary.
        $this->post($this->url('panel.serials.no-show', ['serial' => $others->public_id]))->assertForbidden();
        $this->post($this->url('panel.serials.reinstate', ['serial' => $others->public_id]))->assertForbidden();
        $this->post($this->url('panel.serials.no-show', ['serial' => $ours->public_id]))->assertOk();
        $this->post($this->url('panel.serials.reinstate', ['serial' => $ours->public_id]))->assertOk();

        // The session document (which carries every serial on it) answers to the same scope.
        $this->getJson($this->url('panel.sessions.show', ['session' => $mine->public_id]))->assertOk();
        $this->getJson($this->url('panel.sessions.show', ['session' => $theirs->public_id]))->assertForbidden();
        $this->getJson($this->url('panel.sessions.capacity', ['session' => $theirs->public_id]))->assertForbidden();

        // Opening a clinical encounter from a serial is `view` on that serial, so it is the same boundary again.
        $this->post($this->url('panel.prescription.visits.start', ['serial' => $others->public_id]))->assertForbidden();

        // The doctor screen and its roster were already closed by `queue.call-next`; pinned here because they are
        // one RoleMatrix line away from opening, and they render a colleague's whole roster.
        $this->get($this->url('panel.queue.doctor'))->assertForbidden();
        $this->getJson($this->url('panel.queue.doctor.roster'))->assertForbidden();

        // A second, unfiltered view of a chamber's queue: the session-day page clamps to the same scope.
        $this->get($this->url('panel.scheduling.sessions.index', ['doctor' => $theirs->doctor_id]))->assertForbidden();
        $this->get($this->url('panel.scheduling.sessions.index', ['doctor' => $mine->doctor_id]))->assertOk();
    }

    public function test_the_fee_is_theirs_to_take_at_their_own_doctors_desk_and_nowhere_else(): void
    {
        $this->actingAsStaff(Role::Receptionist);
        $mine = $this->openSession(10, 10, 5, $this->newDoctor());
        $theirs = $this->openSession(10, 10, 5, $this->newDoctor());
        $ours = $this->book($mine, mobile: '01710000541', name: 'Mine')->appointment;
        $others = $this->book($theirs, mobile: '01710000542', name: 'Theirs')->appointment;

        $this->actingAsCompounder($this->doctorOf($mine));

        // "The compounder will assign the patient's vitals, FEE, and arrived-or-not status" — for their doctor.
        $this->postJson($this->url('panel.reception.appointments.collect', ['appointment' => $others->public_id]), [])->assertForbidden();
        $this->postJson($this->url('panel.reception.appointments.collect', ['appointment' => $ours->public_id]), [])
            ->assertOk()->assertJsonPath('payment.payment_status', 'paid');

        // The bill it produced stays legible to them, the colleague's does not.
        $this->get($this->url('panel.billing.invoices.show', ['invoice' => $this->issuedInvoiceFor($ours)->public_id]))->assertOk();
        $this->get($this->url('panel.billing.invoices.show', ['invoice' => $this->issuedInvoiceFor($others)->public_id]))->assertForbidden();
        $this->get($this->url('panel.billing.index'))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('Billing/Invoices')->has('invoices.data', 1));
    }

    public function test_vitals_the_role_s_one_write_reach_only_the_assigned_doctors_patients(): void
    {
        $this->actingAsStaff(Role::Receptionist);
        $mine = $this->openSession(10, 10, 5, $this->newDoctor());
        $theirs = $this->openSession(10, 10, 5, $this->newDoctor());
        $ours = $this->serialOf($mine, '01710000511', 'Mine');
        $others = $this->serialOf($theirs, '01710000512', 'Theirs');

        $this->post($this->url('panel.serials.check-in', ['serial' => $others->public_id]))->assertOk();

        $compounder = $this->actingAsCompounder($this->doctorOf($mine));
        $this->post($this->url('panel.serials.check-in', ['serial' => $ours->public_id]))->assertOk();

        $this->post($this->url('panel.reception.vitals.open', ['serial' => $ours->public_id]))->assertRedirect();
        $this->post($this->url('panel.reception.vitals.open', ['serial' => $others->public_id]))->assertForbidden();
        $this->assertFalse(Gate::forUser($compounder)->allows('recordVitals', $others->refresh()));

        $ourVisit = Visit::query()->where('serial_id', $ours->id)->firstOrFail();
        $theirVisit = Visit::factory()->create(['patient_id' => $others->patient_id, 'doctor_id' => $theirs->doctor_id]);

        $this->get($this->url('panel.reception.vitals.edit', ['visit' => $ourVisit->public_id]))->assertOk();
        $this->get($this->url('panel.reception.vitals.edit', ['visit' => $theirVisit->public_id]))->assertForbidden();

        // The Prescription module's own endpoints are the write path the desk screen posts to — same rule there.
        $this->post($this->url('panel.prescription.vitals.store', ['visit' => $theirVisit->public_id]), ['pulse_bpm' => 80])->assertForbidden();
        $this->get($this->url('panel.prescription.vitals.index', ['visit' => $theirVisit->public_id]))->assertForbidden();
        $this->get($this->url('panel.prescription.visits.show', ['visit' => $theirVisit->public_id]))->assertForbidden();
        $this->post($this->url('panel.prescription.vitals.store', ['visit' => $ourVisit->public_id]), ['pulse_bpm' => 80])->assertRedirect();
    }

    public function test_the_prescription_list_and_every_sheet_on_it_belong_to_the_assigned_doctors(): void
    {
        $this->actingAsStaff(Role::Receptionist);
        $mineDoctor = $this->newDoctor();
        $theirsDoctor = $this->newDoctor();
        $patient = Patient::factory()->create();

        $ours = Prescription::factory()->create(['visit_id' => Visit::factory()->create(['patient_id' => $patient->id, 'doctor_id' => $mineDoctor->id])->id]);
        // The SAME patient, seen by the other doctor: patient-level scope alone would hand this row over.
        $theirs = Prescription::factory()->create(['visit_id' => Visit::factory()->create(['patient_id' => $patient->id, 'doctor_id' => $theirsDoctor->id])->id]);

        $compounder = $this->actingAsCompounder($mineDoctor);

        $this->get($this->url('panel.prescriptions.index'))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('Prescription/Index')
                ->has('prescriptions.data', 1)
                ->where('prescriptions.data.0.id', $ours->public_id)
                ->has('options.doctors', 1)
                ->where('options.doctors.0.public_id', $mineDoctor->public_id));

        $this->assertTrue(Gate::forUser($compounder)->allows('view', $ours), 'BRIEF §5.G.4: the desk prints their doctor\'s sheet');
        $this->assertFalse(Gate::forUser($compounder)->allows('view', $theirs));
        $this->assertFalse(Gate::forUser($compounder)->allows('send', $ours), 'reading a sheet at the desk is not speaking to the patient for the doctor');

        $this->get($this->url('panel.prescription.prescriptions.show', ['prescription' => $theirs->public_id]))->assertForbidden();
        $this->get($this->url('panel.prescription.print', ['prescription' => $theirs->public_id]))->assertForbidden();
    }

    public function test_the_kiosk_link_the_branch_shift_report_and_the_live_queue_are_not_the_branchs_for_a_compounder(): void
    {
        $this->actingAsStaff(Role::Receptionist);
        $mine = $this->openSession(10, 10, 5, $this->newDoctor());
        $theirs = $this->openSession(10, 10, 5, $this->newDoctor());
        $this->serialOf($mine, '01710000521', 'Mine');
        $this->serialOf($theirs, '01710000522', 'Theirs');

        $this->actingAsCompounder($this->doctorOf($mine));

        // A signed 12 h public booking link is issuing serials by another door.
        $this->getJson($this->url('panel.reception.kiosk_url', ['session' => $mine->public_id]))->assertForbidden();

        $this->get($this->url('panel.reception.shift'))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('Reception/Shift')
                ->where('summary.sessions', 1)
                ->where('summary.serials.issued', 1));

        $this->get($this->url('panel.queue.today'))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('Queue/Today')->has('board.sessions', 1)->has('doctors', 1));

        // The unscoped truth, for contrast.
        $this->actingAsStaff(Role::Receptionist);
        $this->get($this->url('panel.reception.shift'))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->where('summary.sessions', 2)->where('summary.serials.issued', 2));
    }

    public function test_revoking_the_assignment_empties_the_desk_on_the_very_next_request(): void
    {
        $this->actingAsStaff(Role::Receptionist);
        $mine = $this->openSession(10, 10, 5, $this->newDoctor());
        $serial = $this->serialOf($mine, '01710000531', 'Mine');

        $compounder = $this->actingAsCompounder($this->doctorOf($mine));
        $this->getJson($this->url('panel.reception.board.data'))->assertOk()->assertJsonCount(1, 'sessions');

        $compounder->assignedDoctors()->detach();

        // No memo anywhere on the path: an unassigned compounder has no desk, not the clinic's.
        $this->getJson($this->url('panel.reception.board.data'))->assertOk()->assertJsonCount(0, 'sessions');
        $this->post($this->url('panel.serials.check-in', ['serial' => $serial->public_id]))->assertForbidden();
        $this->get($this->url('panel.prescriptions.index'))->assertForbidden();
    }
}
