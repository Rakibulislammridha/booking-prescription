<?php

declare(strict_types=1);

namespace Tests\Feature\Reception;

use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Clinic\Enums\Role;
use App\Domain\Prescription\Actions\AmendPrescription;
use App\Domain\Prescription\Actions\StartVisit;
use App\Domain\Serials\Actions\CallSerial;
use App\Domain\Serials\Actions\CheckInSerial;
use App\Domain\Serials\Actions\CompleteConsultation;
use App\Domain\Serials\Actions\StartConsultation;
use App\Domain\Serials\Enums\SerialStatus;
use App\Domain\Shared\Actor;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\Patient;
use App\Models\Tenant\Prescription;
use App\Models\Tenant\Serial;
use App\Models\Tenant\SessionInstance;
use App\Models\Tenant\User;
use App\Models\Tenant\Visit;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\Prescription\PrescriptionTestHelpers;
use Tests\Feature\Reception\Concerns\ReceptionFixtures;
use Tests\TestCase;

/**
 * BRIEF §5.G.4: the prescription is printed at the desk too. A board row whose visit has an issued prescription
 * offers it — as a HANDLE only (public id, verification code, version: what panel.prescription.print binds on),
 * never the snapshot — and the receptionist/compounder role may open the existing print route, which audits the
 * print exactly as the doctor's does. A draft is not a prescription and is not offered; an accountant cannot print.
 */
final class DeskPrescriptionPrintTest extends TestCase
{
    use PrescriptionTestHelpers;
    use ReceptionFixtures;

    private User $doctorUser;

    private Doctor $doctor;

    private SessionInstance $session;

    protected function setUp(): void
    {
        parent::setUp();
        $this->asTenant('a');
        $this->doctorUser = $this->actingAsDoctor();
        $this->doctor = $this->doctorUser->doctor()->firstOrFail();
        $this->session = $this->openSession(10, 10, 5, $this->doctor);
    }

    /**
     * A serial the doctor is with right now, driven through the engine's own edges (SERIAL_ENGINE §6):
     * booked → checked_in (CheckInSerial) → in_consultation (CallSerial — the call IS the transition) and then the
     * optional `consultation_started_at` stamp the writer sets.
     */
    private function consulting(): Serial
    {
        $serial = $this->allocate($this->session, patientId: Patient::factory()->create()->id);
        app(CheckInSerial::class)->handle($serial, $this->staffActor());
        app(CallSerial::class)->handle($serial->fresh(), $this->staffActor());

        return app(StartConsultation::class)->handle($serial->fresh(), $this->staffActor());
    }

    /**
     * The serial's encounter — the one `StartVisitOnSerialCalled` already opened when the serial was called, so
     * this is the product's own row (StartVisit is idempotent per serial, which the unique index enforces).
     */
    private function visitFor(Serial $serial): Visit
    {
        return app(StartVisit::class)->handle($serial->fresh(), Actor::user($this->doctorUser->id));
    }

    /** The doctor's prescription for the serial with one Rx line, issued through the real IssuePrescription. */
    private function issuedFor(Serial $serial): Prescription
    {
        $this->actingAs($this->doctorUser, 'web');
        $draft = $this->draftFor($this->visitFor($serial), $this->doctor);
        $this->savedDraft($draft, $this->itemsPayload([['slug' => 'paracetamol', 'shorthand' => '1+0+1 5d af', 'label' => '500 mg']]));

        return $this->issued($draft->fresh());
    }

    /** @return array<string, mixed> the serial's row of the board JSON */
    private function boardRow(Serial $serial): array
    {
        /** @var array{sessions: array<int, array<string, mixed>>} $json */
        $json = $this->getJson(route('panel.reception.board.data', [], false))->assertOk()->json();
        /** @var array<int, array<string, mixed>> $serials */
        $serials = $this->rowWith($json['sessions'], 'public_id', $this->session->public_id)['serials'];

        return $this->rowWith($serials, 'public_id', $serial->public_id);
    }

    /**
     * The one row of a JSON list whose `$key` is `$value`; fails the test when there is none.
     *
     * @param  array<int, array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function rowWith(array $rows, string $key, string $value): array
    {
        foreach ($rows as $row) {
            if (($row[$key] ?? null) === $value) {
                return $row;
            }
        }

        self::fail("no row with {$key} = {$value}");
    }

    public function test_a_row_with_an_issued_prescription_offers_its_handle_and_nothing_clinical(): void
    {
        // Issuing completes the serial on its own (CompleteConsultationOnPrescriptionIssued), so this row is the
        // finished patient the desk hands the sheet to on the way out.
        $done = $this->consulting();
        $rx = $this->issuedFor($done);
        $this->assertSame(SerialStatus::Completed, $done->fresh()->status);

        $drafting = $this->consulting();
        $this->actingAs($this->doctorUser, 'web');
        $this->draftFor($this->visitFor($drafting), $this->doctor);

        $booked = $this->allocate($this->session, patientId: Patient::factory()->create()->id);

        $this->actingAsStaff(Role::Receptionist);

        $this->assertSame(
            ['public_id' => $rx->public_id, 'verification_code' => $rx->verification_code, 'version' => 1, 'printed' => false],
            $this->boardRow($done)['prescription'],
            'the completed row carries exactly the handle of the issued prescription, plus whether it has been printed',
        );
        $this->assertNull($this->boardRow($drafting)['prescription'], 'a draft is not a prescription yet');
        $this->assertNull($this->boardRow($booked)['prescription'], 'a booked patient has no encounter to print');

        // Nothing of the sheet itself is on the board: no snapshot, no drug names.
        $body = (string) $this->getJson(route('panel.reception.board.data', [], false))->assertOk()->getContent();
        $this->assertStringNotContainsString('snapshot', $body);
        $this->assertStringNotContainsString('Napa', $body);
        $this->assertStringNotContainsString('paracetamol', strtolower($body));

        $this->get(route('panel.reception.board', [], false))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->component('Reception/Board')->where('can.print_prescription', true));
    }

    public function test_the_latest_issued_version_of_a_chain_is_the_one_offered(): void
    {
        $serial = $this->consulting();
        $v1 = $this->issuedFor($serial);

        $draft = app(AmendPrescription::class)->handle($v1, 'Wrong strength', Actor::user($this->doctorUser->id));
        $v2 = $this->issued($draft->fresh());
        $this->assertSame('amended', $v1->fresh()->status->value);
        $this->assertSame(2, $v2->version);

        $this->actingAsStaff(Role::Receptionist);
        $row = $this->boardRow($serial);

        $this->assertSame($v2->public_id, $row['prescription']['public_id']);
        $this->assertSame(2, $row['prescription']['version']);
    }

    /**
     * The friction this rule removes: issuing COMPLETES the serial, and the desk's default view is the active rows,
     * so the one patient who is certainly still at the counter — the one waiting for their printout — was the one
     * the default view hid. A completed row whose issued prescription has never been printed is therefore kept in
     * that view, and the rule clears itself the moment the sheet is printed (`printed_count`, bumped by
     * PrintController). The board's job is to CARRY the answer; shared/offline/board.ts `isAwaitingPrint` applies
     * it identically on both board paths.
     */
    public function test_a_completed_row_is_flagged_awaiting_print_until_the_sheet_is_actually_printed(): void
    {
        Queue::fake();
        $awaiting = $this->consulting();
        $rx = $this->issuedFor($awaiting);

        // A finished patient with nothing to print, and one whose prescription is still a draft: neither is waiting
        // at the counter for paper, so neither may be kept in the default view by this rule.
        $nothing = $this->consulting();
        app(CompleteConsultation::class)->handle($nothing->fresh(), $this->staffActor());
        $drafting = $this->consulting();
        $this->actingAs($this->doctorUser, 'web');
        $this->draftFor($this->visitFor($drafting), $this->doctor);

        $this->actingAsStaff(Role::Receptionist);

        $this->assertSame(SerialStatus::Completed, $awaiting->fresh()->status);
        $this->assertFalse($this->boardRow($awaiting)['prescription']['printed'], 'issued and never printed: the desk still owes this patient their sheet');
        $this->assertTrue($this->awaitingPrint($awaiting), 'so the row stays in the default view');
        $this->assertNull($this->boardRow($nothing)['prescription'], 'a completed row with no prescription is just finished');
        $this->assertFalse($this->awaitingPrint($nothing));
        $this->assertNull($this->boardRow($drafting)['prescription'], 'a draft is not a prescription to hand over');
        $this->assertFalse($this->awaitingPrint($drafting));

        // The compounder prints it. Nothing else about the row changes — the status is still Completed — but the
        // reason to keep it on screen is gone, and it went away without a timer.
        $this->get(route('panel.prescription.print', ['prescription' => $rx->public_id], false))->assertOk();
        $this->assertSame(1, $rx->fresh()->printed_count);

        $row = $this->boardRow($awaiting);
        $this->assertTrue($row['prescription']['printed']);
        $this->assertSame('completed', $row['status'], 'printing is not a status change');
        $this->assertFalse($this->awaitingPrint($awaiting), 'the row leaves the default view the moment it is printed');
    }

    /** The board's own rule, read off the row the board actually sent (shared/offline/board.ts `isAwaitingPrint`). */
    private function awaitingPrint(Serial $serial): bool
    {
        $row = $this->boardRow($serial);

        return $row['status'] === SerialStatus::Completed->value
            && $row['prescription'] !== null
            && $row['prescription']['printed'] === false;
    }

    public function test_the_receptionist_prints_from_the_desk_and_it_is_audited_the_accountant_cannot(): void
    {
        Queue::fake();
        $serial = $this->consulting();
        $rx = $this->issuedFor($serial);

        $receptionist = $this->actingAsStaff(Role::Receptionist);
        $this->assertFalse($receptionist->can('prescriptions.write'), 'the desk cannot write a prescription — printing is the view ability');

        $html = $this->get(route('panel.prescription.print', ['prescription' => $rx->public_id], false))->assertOk()
            ->assertHeader('Content-Type', 'text/html; charset=UTF-8')
            ->getContent();
        $this->assertStringContainsString('window.print()', $html);
        $this->assertStringContainsString((string) $rx->verification_code, $html);

        $rx->refresh();
        $this->assertSame(1, $rx->printed_count);
        $this->assertAudited(AuditAction::Print, $rx, ['event' => 'printed']);

        // The PDF action is the same ability on the same row.
        $this->getJson(route('panel.prescription.pdf', ['prescription' => $rx->public_id], false))->assertStatus(202)->assertJsonPath('status', 'pending');

        $accountant = $this->actingAsStaff(Role::Accountant);
        $this->assertFalse($accountant->can('prescriptions.vitals.record'));
        $this->get(route('panel.reception.board', [], false))->assertOk()
            ->assertInertia(fn (AssertableInertia $p) => $p->where('can.print_prescription', false));
        $this->get(route('panel.prescription.print', ['prescription' => $rx->public_id], false))->assertForbidden();
        $this->getJson(route('panel.prescription.pdf', ['prescription' => $rx->public_id], false))->assertForbidden();
        $this->assertSame(1, $rx->fresh()->printed_count, 'a refused print is not counted');
    }
}
