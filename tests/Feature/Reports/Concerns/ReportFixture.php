<?php

declare(strict_types=1);

namespace Tests\Feature\Reports\Concerns;

use App\Domain\Booking\Enums\AppointmentStatus;
use App\Domain\Booking\Enums\AppointmentType;
use App\Domain\Booking\Enums\BookingChannel;
use App\Domain\Booking\Enums\FeeRule;
use App\Domain\Prescription\Enums\PrescriptionStatus;
use App\Domain\Prescription\Enums\VisitStatus;
use App\Domain\Scheduling\Enums\SessionStatus;
use App\Domain\Serials\Enums\CancelReason;
use App\Domain\Serials\Enums\SerialSource;
use App\Domain\Serials\Enums\SerialStatus;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Branch;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\Patient;
use App\Models\Tenant\Prescription;
use App\Models\Tenant\PrescriptionItem;
use App\Models\Tenant\Serial;
use App\Models\Tenant\SessionInstance;
use App\Models\Tenant\Visit;
use App\Support\Clock;
use App\Tenancy\Facades\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * ONE deliberately constructed clinic whose every report number can be worked out by hand — the whole point of
 * the fixture is that a test asserts an expected value a human computed, not a value the code produced.
 *
 * The shape (all dates clinic-local, Asia/Dhaka; 1 March 2026 is a Sunday):
 *
 *   1 Mar  Dr Rahman, main branch, session A, CLOSED, planned 09:00–13:00, started 09:10, ended 13:25
 *          #1 P1  counter  in 09:05  called 09:20  done 09:32     wait 15   consult 12 min
 *          #2 P2  online   in 09:15  called 09:35  done 09:41     wait 20   consult  6 min
 *          #3 P3  counter  NO-SHOW (never arrived)
 *          #4 P4  online   CANCELLED, reason `transferred` → reissued under Dr Sultana on 2 Mar
 *          #5 P5  walkin   in 10:00  called 10:30  done 10:33     wait 30   consult  3 min
 *          #6 P6  counter  in 11:00  called 11:05  done 15:05     wait  5   consult 4 h → OUTSIDE the clamp
 *
 *   2 Mar  Dr Sultana, main branch, session A, CLOSED, planned 09:00–12:00, started 09:00, ended 11:50
 *          #1 P4  counter  in 09:10  called 09:20  done 09:30     wait 10   consult 10 min
 *          #2 P1  followup in 09:30  called 09:45  done 09:52     wait 15   consult  7 min  ← P1 seen by TWO doctors
 *          #3 P7  kiosk    booked, never arrived (still open)
 *
 *   3 Mar  Dr Rahman, main branch, session A, CANCELLED with two cancelled serials
 *
 *   Historical: P2 also has a visit on 15 January 2026, so P2 is the only RETURNING patient in March.
 *
 * Every number the tests assert is derived from this table and nothing else.
 */
trait ReportFixture
{
    /** @var array<string, Patient> */
    protected array $patients = [];

    /** @var array<string, Visit> */
    protected array $visits = [];

    protected Doctor $rahman;

    protected Doctor $sultana;

    protected Branch $main;

    protected Branch $second;

    /** The clinic-local moment every test runs at: 10 March 2026, 09:00 Dhaka. */
    protected function freezeClinic(): CarbonImmutable
    {
        return Clock::freeze('2026-03-10 09:00');
    }

    protected function seedReportFixture(): void
    {
        $this->freezeClinic();

        $this->main = Branch::query()->where('is_main', true)->firstOrFail();
        $this->second = Branch::factory()->create(['name' => 'মিরপুর শাখা', 'code' => 'MIR2', 'slug' => 'mirpur-2', 'is_main' => false]);
        $this->rahman = Doctor::factory()->complete()->create(['name' => 'Dr. Rahman', 'name_bn' => 'ডা. রহমান', 'slug' => 'dr-rahman-fx', 'code' => 'RAHFX']);
        $this->sultana = Doctor::factory()->complete()->create(['name' => 'Dr. Sultana', 'name_bn' => 'ডা. সুলতানা', 'slug' => 'dr-sultana-fx', 'code' => 'SULFX']);

        foreach (['P1', 'P2', 'P3', 'P4', 'P5', 'P6', 'P7'] as $code) {
            $this->patients[$code] = Patient::factory()->create(['name' => "রোগী {$code}"]);
        }

        $day1 = $this->sessionOn($this->rahman, $this->main, '2026-03-01', '09:00', '13:00', SessionStatus::Closed, '09:10', '13:25');
        $day2 = $this->sessionOn($this->sultana, $this->main, '2026-03-02', '09:00', '12:00', SessionStatus::Closed, '09:00', '11:50');
        $day3 = $this->sessionOn($this->rahman, $this->main, '2026-03-03', '09:00', '13:00', SessionStatus::Cancelled);

        $s1 = $this->serial($day1, 1, 'P1', SerialSource::Counter, SerialStatus::Completed, '2026-03-01 09:05', '2026-03-01 09:20', '2026-03-01 09:32');
        $s2 = $this->serial($day1, 2, 'P2', SerialSource::Online, SerialStatus::Completed, '2026-03-01 09:15', '2026-03-01 09:35', '2026-03-01 09:41');
        $this->serial($day1, 3, 'P3', SerialSource::Counter, SerialStatus::NoShow);
        $this->serial($day1, 4, 'P4', SerialSource::Online, SerialStatus::Cancelled, cancelReason: CancelReason::Transferred);
        $s5 = $this->serial($day1, 5, 'P5', SerialSource::Walkin, SerialStatus::Completed, '2026-03-01 10:00', '2026-03-01 10:30', '2026-03-01 10:33');
        $s6 = $this->serial($day1, 6, 'P6', SerialSource::Counter, SerialStatus::Completed, '2026-03-01 11:00', '2026-03-01 11:05', '2026-03-01 15:05');

        $s7 = $this->serial($day2, 1, 'P4', SerialSource::Counter, SerialStatus::Completed, '2026-03-02 09:10', '2026-03-02 09:20', '2026-03-02 09:30');
        $s8 = $this->serial($day2, 2, 'P1', SerialSource::Followup, SerialStatus::Completed, '2026-03-02 09:30', '2026-03-02 09:45', '2026-03-02 09:52');
        $this->serial($day2, 3, 'P7', SerialSource::Kiosk, SerialStatus::Booked);

        $this->serial($day3, 1, 'P5', SerialSource::Counter, SerialStatus::Cancelled, cancelReason: CancelReason::SessionCancelled);
        $this->serial($day3, 2, 'P6', SerialSource::Counter, SerialStatus::Cancelled, cancelReason: CancelReason::SessionCancelled);

        // Visits — one per completed consultation, plus P2's January history that makes them RETURNING in March.
        $this->visits['V1'] = $this->visit($s1, '2026-03-01 09:20', [
            ['icd10_code' => 'E11', 'title' => 'Type 2 diabetes', 'kind' => 'final', 'sort' => 0],
            ['icd10_code' => 'I10', 'title' => 'Hypertension', 'kind' => 'provisional', 'sort' => 1],
        ], followUpOn: '2026-03-08');
        $this->visits['V2'] = $this->visit($s2, '2026-03-01 09:35', [
            ['icd10_code' => 'E11', 'title' => 'Type 2 diabetes mellitus', 'kind' => 'final', 'sort' => 0],
        ], followUpOn: '2026-03-09');
        $this->visits['V3'] = $this->visit($s5, '2026-03-01 10:30', [
            ['icd10_code' => null, 'title' => 'Viral fever', 'kind' => 'provisional', 'sort' => 0],
        ], followUpOn: '2026-03-07');
        $this->visits['V4'] = $this->visit($s6, '2026-03-01 11:05', [
            ['icd10_code' => 'E11', 'title' => 'Type 2 diabetes', 'kind' => 'provisional', 'sort' => 0],
            ['icd10_code' => 'E11', 'title' => 'Type 2 diabetes', 'kind' => 'final', 'sort' => 1],
        ], followUpOn: '2026-03-20');
        $this->visits['V5'] = $this->visit($s7, '2026-03-02 09:20', [
            ['icd10_code' => 'I10', 'title' => 'Hypertension', 'kind' => 'final', 'sort' => 0],
        ]);
        $this->visits['V6'] = $this->visit($s8, '2026-03-02 09:45', [
            ['icd10_code' => null, 'title' => 'viral fever', 'kind' => 'final', 'sort' => 0],
        ]);

        // P2's earlier visit: outside the March range, so P2 is RETURNING while everyone else is NEW.
        Visit::factory()->create([
            'patient_id' => $this->patients['P2']->id,
            'doctor_id' => $this->rahman->id,
            'branch_id' => $this->main->id,
            'status' => VisitStatus::Closed,
            'started_at' => $this->utc('2026-01-15 10:00'),
        ]);

        $this->followUpAppointments();
        $this->prescriptions();
    }

    /** Follow-up bookings: one kept, one booked-not-kept, one draft-only, one not yet due. */
    private function followUpAppointments(): void
    {
        $this->followUp($this->visits['V1'], AppointmentStatus::Completed, '2026-03-08');   // kept
        $this->followUp($this->visits['V2'], AppointmentStatus::Confirmed, '2026-03-09');   // booked, not kept
        $this->followUp($this->visits['V3'], AppointmentStatus::Draft, '2026-03-07');       // the system's own draft — not a booking
        $this->followUp($this->visits['V4'], AppointmentStatus::Confirmed, '2026-03-20');   // booked, not yet due
    }

    /**
     * Prescriptions. Rx3 is AMENDED and Rx4 supersedes it — only Rx4 may be counted, or the amendment would
     * double-count its drugs. Rx6 is a draft and must never appear.
     */
    private function prescriptions(): void
    {
        $this->issued($this->visits['V1'], '2026-03-01 09:30', [['Paracetamol', 'Napa'], ['Metformin', null]]);
        $this->issued($this->visits['V2'], '2026-03-01 09:40', [['Paracetamol', 'Napa']]);

        // V4 was amended: version 1 becomes `amended` (superseded) and version 2 is the live sheet. Counting
        // both would double-count the drug, which is exactly what TopDrugsQuery's status filter prevents.
        $rx3 = $this->issued($this->visits['V4'], '2026-03-01 11:10', [['Paracetamol', 'Ace']], PrescriptionStatus::Amended);
        $this->issued($this->visits['V4'], '2026-03-01 11:20', [['Paracetamol', 'Ace']], PrescriptionStatus::Issued, version: 2, supersedes: $rx3);

        $this->issued($this->visits['V5'], '2026-03-02 09:25', [['Paracetamol', 'Napa']]);

        // A draft: written but never handed to the patient, so it must not appear in any drug count.
        $this->issued($this->visits['V6'], null, [['Omeprazole', 'Losectil']], PrescriptionStatus::Draft);
    }

    /**
     * Items may only be written while the parent is a draft (`prescription_children_immutable_trg`), so the
     * fixture builds every prescription the way the app does: draft → lines → issue.
     *
     * @param  array<int, array{0: string, 1: string|null}>  $items
     */
    protected function issued(Visit $visit, ?string $issuedAt, array $items, PrescriptionStatus $status = PrescriptionStatus::Issued, int $version = 1, ?Prescription $supersedes = null): Prescription
    {
        $rx = Prescription::factory()->create([
            'visit_id' => $visit->id,
            'patient_id' => $visit->patient_id,
            'doctor_id' => $visit->doctor_id,
            'branch_id' => $visit->branch_id,
            'status' => PrescriptionStatus::Draft,
            'version' => $version,
            'supersedes_prescription_id' => $supersedes?->id,
        ]);

        foreach ($items as [$generic, $brand]) {
            PrescriptionItem::factory()->create([
                'prescription_id' => $rx->id,
                'generic_name' => $generic,
                'brand_name' => $brand,
            ]);
        }

        if ($status !== PrescriptionStatus::Draft && $issuedAt !== null) {
            $rx->forceFill([
                'status' => $status,
                'issued_at' => $this->utc($issuedAt),
                'snapshot' => ['v' => 1],
                'snapshot_sha256' => hash('sha256', $rx->public_id),
                'verification_code' => strtoupper(substr(md5($rx->public_id), 0, 10)),
            ])->save();
        }

        return $rx->refresh();
    }

    protected function sessionOn(Doctor $doctor, Branch $branch, string $date, string $start, string $end, SessionStatus $status, ?string $actualStart = null, ?string $actualEnd = null): SessionInstance
    {
        return SessionInstance::factory()->create([
            'doctor_id' => $doctor->id,
            'branch_id' => $branch->id,
            'session_date' => $date,
            'session_code' => 'A',
            'status' => $status,
            'planned_start_at' => $this->utc("{$date} {$start}"),
            'planned_end_at' => $this->utc("{$date} {$end}"),
            'actual_start_at' => $actualStart === null ? null : $this->utc("{$date} {$actualStart}"),
            'actual_end_at' => $actualEnd === null ? null : $this->utc("{$date} {$actualEnd}"),
        ]);
    }

    protected function serial(SessionInstance $session, int $number, string $patient, SerialSource $source, SerialStatus $status, ?string $checkedIn = null, ?string $called = null, ?string $completed = null, ?CancelReason $cancelReason = null): Serial
    {
        return Serial::factory()->create([
            'session_instance_id' => $session->id,
            'number' => $number,
            'display_code' => 'A-'.str_pad((string) $number, 3, '0', STR_PAD_LEFT),
            'position' => $number * 1000000,
            'patient_id' => $this->patients[$patient]->id,
            'source' => $source,
            'status' => $status,
            'booked_at' => $session->planned_start_at->subDay(),
            'checked_in_at' => $checkedIn === null ? null : $this->utc($checkedIn),
            'called_at' => $called === null ? null : $this->utc($called),
            'completed_at' => $completed === null ? null : $this->utc($completed),
            'no_show_at' => $status === SerialStatus::NoShow ? $session->planned_start_at : null,
            'cancelled_at' => $status === SerialStatus::Cancelled ? $session->planned_start_at : null,
            'cancel_reason_code' => $cancelReason,
        ]);
    }

    /** @param array<int, array<string, mixed>> $diagnoses */
    protected function visit(Serial $serial, string $startedAt, array $diagnoses, ?string $followUpOn = null): Visit
    {
        return Visit::factory()->fromSerial($serial)->create([
            'status' => VisitStatus::Closed,
            'started_at' => $this->utc($startedAt),
            'diagnoses' => $diagnoses,
            'follow_up_on' => $followUpOn,
        ]);
    }

    protected function followUp(Visit $visit, AppointmentStatus $status, string $date): Appointment
    {
        // `appointments_draft_session_check`: only a draft may have no session. A real follow-up booking is a
        // date on a doctor's board, so the fixture gives it one — scheduled, no actual start or end, therefore
        // invisible to the overrun report and carrying no serials of its own.
        $session = $status === AppointmentStatus::Draft ? null : $this->sessionOn(
            $visit->doctor_id === $this->rahman->id ? $this->rahman : $this->sultana,
            $this->main,
            $date,
            '09:00',
            '13:00',
            SessionStatus::Scheduled,
        );

        return Appointment::factory()->create([
            'patient_id' => $visit->patient_id,
            'doctor_id' => $visit->doctor_id,
            'branch_id' => $visit->branch_id,
            'session_instance_id' => $session?->id,
            'serial_id' => null,
            'type' => AppointmentType::Followup,
            'channel' => BookingChannel::Followup,
            'status' => $status,
            'scheduled_date' => $date,
            'fee_rule' => FeeRule::FollowupPaid,
            'follow_up_of_visit_id' => $visit->id,
        ]);
    }

    /**
     * A query summary's sub-array as a typed collection. The query objects return `array<string, mixed>`
     * documents, so this is where the shape is asserted once instead of at every call site.
     *
     * @return Collection<int, array<string, mixed>>
     */
    protected function rows(mixed $value): Collection
    {
        /** @var array<int, array<string, mixed>> $rows */
        $rows = is_array($value) ? array_values(array_filter($value, 'is_array')) : [];

        return new Collection($rows);
    }

    /** A clinic-local wall clock ("2026-03-01 09:05") as the UTC instant the column actually stores. */
    protected function utc(string $localDateTime): CarbonImmutable
    {
        return CarbonImmutable::parse($localDateTime, Tenancy::current()->timezone ?? Clock::DEFAULT_TIMEZONE)->utc();
    }
}
