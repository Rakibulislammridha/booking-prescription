<?php

declare(strict_types=1);

namespace Database\Seeders\Reports;

use App\Domain\Billing\Enums\InvoiceItemType;
use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Enums\PaymentGateway;
use App\Domain\Billing\Enums\PaymentMethod;
use App\Domain\Billing\Enums\PaymentTxnStatus;
use App\Domain\Billing\Enums\RefundReason;
use App\Domain\Billing\Enums\RefundStatus;
use App\Domain\Booking\Enums\AppointmentStatus;
use App\Domain\Booking\Enums\AppointmentType;
use App\Domain\Booking\Enums\BookingChannel;
use App\Domain\Booking\Enums\FeeRule;
use App\Domain\Prescription\Enums\PrescriptionStatus;
use App\Domain\Prescription\Enums\VisitStatus;
use App\Domain\Scheduling\Enums\ScheduleMode;
use App\Domain\Scheduling\Enums\SessionStatus;
use App\Domain\Serials\Enums\CancelReason;
use App\Domain\Serials\Enums\SerialPool;
use App\Domain\Serials\Enums\SerialPriority;
use App\Domain\Serials\Enums\SerialSource;
use App\Domain\Serials\Enums\SerialStatus;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Branch;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\Invoice;
use App\Models\Tenant\InvoiceItem;
use App\Models\Tenant\Patient;
use App\Models\Tenant\Payment;
use App\Models\Tenant\Prescription;
use App\Models\Tenant\PrescriptionItem;
use App\Models\Tenant\Refund;
use App\Models\Tenant\Serial;
use App\Models\Tenant\SerialPool as SerialPoolModel;
use App\Models\Tenant\SessionInstance;
use App\Models\Tenant\Visit;
use App\Support\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Several weeks of realistic OPD history for the DEMO tenant, so the reports have something to show and the
 * sales pitch has a screen worth looking at (`tenants:seed --tenant=demo --class="Database\Seeders\Reports\ReportsDemoSeeder"`).
 *
 * Shape: eight weeks up to today, every doctor the clinic already has, both branches, a morning and an evening
 * session per weekday, a mix of channels weighted the way a Bangladeshi clinic actually books (counter-heavy,
 * a growing online share), a believable no-show rate, real Bangla diagnoses and the drugs a general OPD writes.
 * Fridays are quiet, as they are.
 *
 * Deterministic: seeded `mt_srand`, so re-running produces the same clinic. Idempotent by natural key — it
 * skips any (branch, doctor, date, session) it has already materialised, and never truncates (CONVENTIONS §10).
 */
final class ReportsDemoSeeder extends Seeder
{
    private const WEEKS = 8;

    /**
     * Follow-ups are booked in a SECOND pass: a visit on 1 March advises a follow-up on the 15th, and that
     * session does not exist yet while the loop is still walking the 1st.
     *
     * @var array<int, array{visit: Visit, on: string}>
     */
    private array $pendingFollowUps = [];

    /** ICD-10 code, English title, Bangla title, relative weight. */
    private const DIAGNOSES = [
        ['J06.9', 'Acute upper respiratory infection', 'তীব্র শ্বাসনালীর সংক্রমণ', 18],
        ['E11', 'Type 2 diabetes mellitus', 'টাইপ ২ ডায়াবেটিস', 15],
        ['I10', 'Essential hypertension', 'উচ্চ রক্তচাপ', 14],
        ['K29.7', 'Gastritis, unspecified', 'গ্যাস্ট্রাইটিস', 11],
        ['A09', 'Infectious gastroenteritis', 'ডায়রিয়া ও পেটের সংক্রমণ', 8],
        ['J45', 'Asthma', 'হাঁপানি', 7],
        ['M54.5', 'Low back pain', 'কোমর ব্যথা', 7],
        ['B54', 'Unspecified malaria', 'ম্যালেরিয়া', 3],
        [null, 'Viral fever', 'ভাইরাল জ্বর', 12],
        [null, 'Anaemia (clinical)', 'রক্তশূন্যতা', 5],
    ];

    /** Generic, brand, strength, weight — the shelf of a Bangladeshi OPD. */
    private const DRUGS = [
        ['Paracetamol', 'Napa', '500 mg', 22],
        ['Esomeprazole', 'Sergel', '20 mg', 16],
        ['Metformin', 'Comet', '500 mg', 12],
        ['Amlodipine', 'Amdocal', '5 mg', 11],
        ['Cefixime', 'Cef-3', '400 mg', 9],
        ['Montelukast', 'Montene', '10 mg', 8],
        ['Azithromycin', 'Zithrin', '500 mg', 7],
        ['Omeprazole', null, '20 mg', 6],
        ['Salbutamol', 'Sultolin', '2 mg', 5],
        ['Ferrous fumarate', 'Zeefol', '150 mg', 4],
    ];

    private const COMPLAINTS = [
        ['Fever', 'জ্বর'], ['Cough', 'কাশি'], ['Headache', 'মাথাব্যথা'], ['Abdominal pain', 'পেট ব্যথা'],
        ['Shortness of breath', 'শ্বাসকষ্ট'], ['Weakness', 'দুর্বলতা'], ['Loose motion', 'পাতলা পায়খানা'],
    ];

    private const PATIENT_NAMES = [
        'মোঃ রহিম উদ্দিন', 'ফাতেমা খাতুন', 'আব্দুল করিম', 'নাসরিন সুলতানা', 'জাহিদ হাসান', 'সুমাইয়া আক্তার',
        'রফিকুল ইসলাম', 'শাহানা পারভীন', 'মোঃ ইব্রাহিম', 'তানজিলা রহমান', 'কামরুল হাসান', 'রোকেয়া বেগম',
        'সাইফুল ইসলাম', 'নুসরাত জাহান', 'আনোয়ার হোসেন', 'মরিয়ম আক্তার', 'জসিম উদ্দিন', 'সাবিনা ইয়াসমিন',
    ];

    public function run(): void
    {
        mt_srand(20260907);

        $branches = Branch::query()->where('is_active', true)->orderByDesc('is_main')->get();
        $doctors = Doctor::query()->where('is_active', true)->orderBy('id')->get();

        if ($branches->isEmpty() || $doctors->isEmpty()) {
            $this->command->getOutput()->writeln('  <comment>no branches or doctors — run the demo tenant seeder first</comment>');

            return;
        }

        $patients = $this->patients(400);
        $today = Clock::today();
        $sessions = 0;
        $serials = 0;

        for ($back = self::WEEKS * 7; $back >= 0; $back--) {
            $date = $today->subDays($back);
            $weekday = (int) $date->dayOfWeek;

            if ($weekday === 5) {
                continue;   // Friday: the clinic is closed
            }

            foreach ($doctors as $index => $doctor) {
                $branch = $branches[$index % $branches->count()];

                foreach ([['A', 9, 13], ['B', 17, 21]] as [$code, $startHour, $endHour]) {
                    if ($code === 'B' && $index % 2 === 1) {
                        continue;   // half the doctors run mornings only
                    }

                    $session = $this->session($branch, $doctor, $date, $code, $startHour, $endHour, $back);

                    if ($session === null) {
                        continue;
                    }

                    $sessions++;
                    $serials += $this->fill($session, $doctor, $branch, $patients, $date, $back);
                }
            }
        }

        $followUps = $this->flushFollowUps();

        $this->command->getOutput()->writeln("  seeded {$sessions} sessions, {$serials} serials and {$followUps} follow-up bookings over ".(self::WEEKS * 7).' days');
    }

    /** @return array<int, Patient> */
    private function patients(int $count): array
    {
        $existing = Patient::query()->orderBy('id')->get()->all();
        $today = Clock::today();

        // `patients.patient_code` comes from `patient_code_seq`, and the tenant's first rows may have been
        // written with literal codes (the clinic demo seeder) rather than from the sequence. Realign it before
        // adding any patient, or the first insert collides with a code that already exists.
        DB::statement("select setval('patient_code_seq', greatest((select coalesce(max(nullif(regexp_replace(patient_code, '\\D', '', 'g'), '')::bigint), 0) from patients), 1))");

        for ($i = count($existing); $i < $count; $i++) {
            $existing[] = Patient::query()->create([
                'name' => self::PATIENT_NAMES[$i % count(self::PATIENT_NAMES)].' '.($i + 1),
                'mobile' => '+8801'.str_pad((string) (700000000 + $i), 9, '0', STR_PAD_LEFT),
                'is_mobile_owner' => true,
                'gender' => $i % 2 === 0 ? 'male' : 'female',
                'dob' => $today->subYears(18 + ($i * 7) % 55)->toDateString(),
                'dob_is_estimated' => false,
                'district' => ['Dhaka', 'Chattogram', 'Sylhet', 'Khulna'][$i % 4],
                'preferred_language' => 'bn',
                'tags' => [],
                'source' => 'counter',
                'is_active' => true,
                'visit_count' => 0,
            ]);
        }

        return $existing;
    }

    private function session(Branch $branch, Doctor $doctor, CarbonImmutable $date, string $code, int $startHour, int $endHour, int $daysBack): ?SessionInstance
    {
        $existing = SessionInstance::query()
            ->where('branch_id', $branch->id)->where('doctor_id', $doctor->id)
            ->where('session_date', $date->toDateString())->where('session_code', $code)
            ->first();

        if ($existing !== null) {
            return null;   // idempotent: this day is already seeded
        }

        $plannedStart = $date->setTime($startHour, 0)->utc();
        $plannedEnd = $date->setTime($endHour, 0)->utc();
        $past = $daysBack > 0;
        $overrun = self::pick([0, 5, 12, 20, 35, 55]);

        $session = SessionInstance::query()->create([
            'branch_id' => $branch->id,
            'doctor_id' => $doctor->id,
            'session_date' => $date->toDateString(),
            'session_code' => $code,
            'mode' => ScheduleMode::Serial,
            'status' => $past ? SessionStatus::Closed : SessionStatus::Running,
            'planned_start_at' => $plannedStart,
            'planned_end_at' => $plannedEnd,
            'actual_start_at' => $plannedStart->addMinutes(self::pick([0, 3, 8, 15, 25])),
            'actual_end_at' => $past ? $plannedEnd->addMinutes($overrun) : null,
            'pause_seconds' => 0,
            'delay_minutes' => 0,
            'max_serials' => 30,
            'online_quota' => 10,
            'counter_quota' => 16,
            'buffer_quota' => 4,
            'avg_consult_seconds' => 360,
            'consult_samples' => 0,
            'auto_noshow_after' => 3,
            'fee_new_paisa' => 80000,
            'fee_followup_paisa' => 50000,
            'version' => 1,
        ]);

        foreach ([[SerialPool::Counter, 1, 16], [SerialPool::Online, 17, 26], [SerialPool::Buffer, 27, 30]] as [$pool, $from, $to]) {
            SerialPoolModel::query()->create([
                'session_instance_id' => $session->id,
                'pool' => $pool,
                'range_start' => $from,
                'range_end' => $to,
                'next_number' => $from,
                'issued_count' => 0,
                'lock_version' => 0,
            ]);
        }

        return $session;
    }

    /** @param array<int, Patient> $patients */
    private function fill(SessionInstance $session, Doctor $doctor, Branch $branch, array $patients, CarbonImmutable $date, int $daysBack): int
    {
        // Sundays and Mondays are the busy days of a Dhaka OPD; the tail of the week thins out.
        $weekday = (int) $date->dayOfWeek;
        $load = match ($weekday) {
            0, 1 => 22,
            2, 3 => 17,
            4 => 12,
            default => 14,
        };
        $count = max(4, $load + self::pick([-3, -1, 0, 1, 2, 4]));
        $past = $daysBack > 0;
        $issued = 0;

        // The patient book GROWS with the calendar: an early day can only draw from the patients the clinic had
        // then, so the new-vs-returning report tells the story of a clinic acquiring patients rather than a
        // fixed roster reshuffled every day.
        $known = (int) max(60, round(count($patients) * (1 - $daysBack / (self::WEEKS * 7 + 1)) * 0.9 + 40));

        for ($n = 1; $n <= $count; $n++) {
            $patient = $patients[mt_rand(0, min($known, count($patients)) - 1)];
            $source = self::weighted([
                SerialSource::Counter->value => 46,
                SerialSource::Online->value => 22,
                SerialSource::Walkin->value => 16,
                SerialSource::Followup->value => 10,
                SerialSource::Kiosk->value => 6,
            ]);

            $roll = mt_rand(1, 100);
            // Today is mid-session: some are done, a couple are in the room or waiting, the rest have not
            // arrived — otherwise the dashboard's live tiles read zero on a day the clinic is obviously open.
            $status = match (true) {
                ! $past && $n > $count * 0.55 => SerialStatus::Booked,
                ! $past && $n > $count * 0.5 => SerialStatus::InConsultation,
                ! $past && $n > $count * 0.4 => SerialStatus::CheckedIn,
                $roll <= 8 => SerialStatus::NoShow,
                $roll <= 12 => SerialStatus::Cancelled,
                default => SerialStatus::Completed,
            };

            // Arrivals bunch at the start of the session, which is exactly what the heatmap should show.
            $arrivalMinutes = (int) round(self::skew() * ($session->planned_end_at->diffInMinutes($session->planned_start_at) * -1));
            $checkedIn = $session->planned_start_at->addMinutes(max(-20, $arrivalMinutes - 15));
            $wait = self::pick([4, 8, 11, 15, 18, 22, 27, 34, 45]);
            $called = $checkedIn->addMinutes($wait);
            $consult = self::pick([3, 4, 5, 6, 7, 8, 9, 11, 14]);

            $serial = Serial::query()->create([
                'session_instance_id' => $session->id,
                'number' => $n,
                'display_code' => $session->session_code.'-'.str_pad((string) $n, 3, '0', STR_PAD_LEFT),
                'position' => $n * 1000000,
                'pool' => $n <= 16 ? SerialPool::Counter : ($n <= 26 ? SerialPool::Online : SerialPool::Buffer),
                'status' => $status,
                'priority' => SerialPriority::Normal,
                'source' => $source,
                'patient_id' => $patient->id,
                'booked_at' => $session->planned_start_at->subDays(self::pick([0, 0, 1, 2, 3])),
                'checked_in_at' => in_array($status, [SerialStatus::Completed, SerialStatus::CheckedIn, SerialStatus::InConsultation], true) ? $checkedIn : null,
                'called_at' => in_array($status, [SerialStatus::Completed, SerialStatus::InConsultation], true) ? $called : null,
                'consultation_started_at' => in_array($status, [SerialStatus::Completed, SerialStatus::InConsultation], true) ? $called : null,
                'completed_at' => $status === SerialStatus::Completed ? $called->addMinutes($consult) : null,
                'no_show_at' => $status === SerialStatus::NoShow ? $session->planned_end_at : null,
                'cancelled_at' => $status === SerialStatus::Cancelled ? $session->planned_start_at : null,
                'cancel_reason_code' => $status === SerialStatus::Cancelled ? CancelReason::PatientRequest : null,
                'passed_count' => 0,
                'skip_count' => 0,
            ]);

            $issued++;

            if ($status !== SerialStatus::Completed) {
                continue;
            }

            $visit = $this->visit($serial, $patient, $doctor, $branch, $called, $date);
            $this->prescription($visit, $called->addMinutes($consult));
            $this->money($visit, $doctor, $branch, $patient, $called, $source);
        }

        $this->recountSession($session);

        return $issued;
    }

    /** The counters `CountsRecalculator` keeps in production; the reports aggregate from `serials` regardless. */
    private function recountSession(SessionInstance $session): void
    {
        $counts = DB::table('serials')
            ->where('session_instance_id', $session->id)
            ->selectRaw("count(*) filter (where status = 'booked') as booked,
                count(*) filter (where status = 'checked_in') as checked_in,
                count(*) filter (where status = 'in_consultation') as in_consultation,
                count(*) filter (where status = 'completed') as completed,
                count(*) filter (where status = 'no_show') as no_show,
                count(*) filter (where status = 'cancelled') as cancelled,
                count(*) filter (where status = 'postponed') as postponed,
                count(*) filter (where completed_at is not null and called_at is not null) as samples")
            ->first();

        $read = static fn (string $key): int => is_object($counts) && isset($counts->{$key}) ? (int) $counts->{$key} : 0;

        $session->forceFill([
            'booked_count' => $read('booked'),
            'checked_in_count' => $read('checked_in'),
            'in_consultation_count' => $read('in_consultation'),
            'completed_count' => $read('completed'),
            'no_show_count' => $read('no_show'),
            'cancelled_count' => $read('cancelled'),
            'postponed_count' => $read('postponed'),
            'consult_samples' => $read('samples'),
        ])->save();
    }

    private function visit(Serial $serial, Patient $patient, Doctor $doctor, Branch $branch, CarbonImmutable $startedAt, CarbonImmutable $date): Visit
    {
        $diagnoses = [];
        $primary = self::weightedRow(self::DIAGNOSES);
        $diagnoses[] = ['icd10_code' => $primary[0], 'title' => $primary[1], 'kind' => 'final', 'sort' => 0];

        if (mt_rand(1, 100) <= 25) {
            $second = self::weightedRow(self::DIAGNOSES);
            $diagnoses[] = ['icd10_code' => $second[0], 'title' => $second[1], 'kind' => 'provisional', 'sort' => 1];
        }

        $complaint = self::COMPLAINTS[mt_rand(0, count(self::COMPLAINTS) - 1)];
        $followUp = mt_rand(1, 100) <= 45 ? $date->addDays(self::pick([7, 10, 14, 15, 30]))->toDateString() : null;

        $visit = Visit::query()->create([
            'appointment_id' => null,
            'serial_id' => $serial->id,
            'session_instance_id' => $serial->session_instance_id,
            'patient_id' => $patient->id,
            'doctor_id' => $doctor->id,
            'branch_id' => $branch->id,
            'type' => 'opd',
            'status' => VisitStatus::Closed,
            'started_at' => $startedAt,
            'ended_at' => $startedAt->addMinutes(6),
            'chief_complaints' => [['text' => $complaint[0], 'text_bn' => $complaint[1], 'duration' => self::pick(['2d', '3d', '1w', '2w']), 'sort' => 0]],
            'examination_findings' => 'BP '.self::pick(['120/80', '130/85', '140/90']).', Temp '.self::pick(['98.6F', '100.2F', '101F']),
            'diagnoses' => $diagnoses,
            'follow_up_on' => $followUp,
        ]);

        if ($followUp !== null) {
            $this->pendingFollowUps[] = ['visit' => $visit, 'on' => $followUp];
        }

        return $visit;
    }

    /**
     * A follow-up is advised often, booked most of the time, and kept rather less — which is the point of the
     * compliance report. Run after every session exists, so a booking can find the day it belongs to.
     */
    private function flushFollowUps(): int
    {
        $created = 0;

        foreach ($this->pendingFollowUps as ['visit' => $visit, 'on' => $on]) {
            $roll = mt_rand(1, 100);

            if ($roll <= 22) {
                continue;   // advised, never booked at all
            }

            $target = SessionInstance::query()
                ->where('doctor_id', $visit->doctor_id)
                ->where('session_date', $on)
                ->orderBy('id')
                ->first();

            // `appointments_patient_id_session_instance_id_uniq_p`: one live booking per patient per session.
            // A patient already booked into that session keeps the booking they have.
            $taken = $target !== null && Appointment::query()
                ->where('patient_id', $visit->patient_id)
                ->where('session_instance_id', $target->id)
                ->whereNotIn('status', [AppointmentStatus::Cancelled->value, AppointmentStatus::NoShow->value])
                ->exists();

            if ($taken) {
                continue;
            }

            // No session on that day (a Friday, or past the seeded window): the system's own DRAFT placeholder,
            // which the compliance report deliberately does NOT count as a booking.
            $status = $target === null
                ? AppointmentStatus::Draft
                : ($roll <= 70 ? AppointmentStatus::Completed : AppointmentStatus::Confirmed);

            Appointment::query()->create([
                'patient_id' => $visit->patient_id,
                'doctor_id' => $visit->doctor_id,
                'branch_id' => $visit->branch_id,
                'session_instance_id' => $target?->id,
                'type' => AppointmentType::Followup,
                'channel' => BookingChannel::Followup,
                'status' => $status,
                'scheduled_date' => $on,
                'list_fee_paisa' => 80000,
                'fee_paisa' => 50000,
                'fee_rule' => FeeRule::FollowupPaid,
                'payment_status' => $status === AppointmentStatus::Completed ? 'paid' : 'unpaid',
                'follow_up_of_visit_id' => $visit->id,
                'booked_by_patient' => false,
            ]);

            $created++;
        }

        $this->pendingFollowUps = [];

        return $created;
    }

    private function prescription(Visit $visit, CarbonImmutable $issuedAt): void
    {
        $rx = Prescription::query()->create([
            'visit_id' => $visit->id,
            'patient_id' => $visit->patient_id,
            'doctor_id' => $visit->doctor_id,
            'branch_id' => $visit->branch_id,
            'version' => 1,
            'status' => PrescriptionStatus::Draft,
            'language' => 'both',
            'printed_count' => 0,
            'delivered_channels' => [],
        ]);

        $lines = mt_rand(2, 4);
        $used = [];

        for ($i = 0; $i < $lines; $i++) {
            $drug = self::weightedRow(self::DRUGS);

            if (in_array($drug[0], $used, true)) {
                continue;
            }

            $used[] = $drug[0];

            PrescriptionItem::query()->create([
                'prescription_id' => $rx->id,
                'sort_order' => $i + 1,
                'generic_name' => $drug[0],
                'brand_name' => $drug[1],
                'strength' => $drug[2],
                'form' => 'Tablet',
                'route' => 'Oral',
                'dose_schedule' => self::pick(['1+0+1', '1+1+1', '0+0+1', '1+0+0']),
                'dose_json' => [],
                'duration_days' => self::pick([3, 5, 7, 10, 14]),
                'duration_text' => null,
                'quantity' => null,
                'quantity_unit' => 'tab',
                'timing' => self::pick(['after', 'before', 'any']),
                'is_continued' => false,
                'safety_overrides' => [],
            ]);
        }

        $rx->forceFill([
            'status' => PrescriptionStatus::Issued,
            'issued_at' => $issuedAt,
            'snapshot' => ['v' => 1, 'demo' => true],
            'snapshot_sha256' => hash('sha256', $rx->public_id),
            'verification_code' => strtoupper(substr(str_replace(['I', 'L', 'O', 'U'], '9', $rx->public_id), 0, 10)),
        ])->save();

        $visit->forceFill(['current_prescription_id' => $rx->id])->save();
    }

    private function money(Visit $visit, Doctor $doctor, Branch $branch, Patient $patient, CarbonImmutable $paidAt, string $source): void
    {
        $isFollowUp = $source === SerialSource::Followup->value;
        $total = $isFollowUp ? 50000 : 80000;

        $invoice = Invoice::query()->create([
            'number' => 'INV-DEMO-'.str_pad((string) mt_rand(1, 999999), 6, '0', STR_PAD_LEFT).'-'.$visit->id,
            'patient_id' => $patient->id,
            'visit_id' => $visit->id,
            'doctor_id' => $doctor->id,
            'branch_id' => $branch->id,
            'status' => InvoiceStatus::Paid,
            'subtotal_paisa' => $total,
            'total_paisa' => $total,
            'paid_paisa' => $total,
            'issued_at' => $paidAt,
            'paid_at' => $paidAt,
        ]);

        // The invoice LINE is what carries the commission split (SCHEMA §3.5): the revenue-share report reads
        // the frozen `doctor_share_paisa`, never the rule, so the demo has to freeze one here too.
        $doctorShare = (int) round($total * 0.6);

        InvoiceItem::query()->create([
            'invoice_id' => $invoice->id,
            'sort_order' => 1,
            'type' => $isFollowUp ? InvoiceItemType::Followup : InvoiceItemType::Consultation,
            'description' => $isFollowUp ? 'Follow-up consultation' : 'Consultation',
            'doctor_id' => $doctor->id,
            'quantity' => 1,
            'unit_price_paisa' => $total,
            'line_total_paisa' => $total,
            'doctor_share_paisa' => $doctorShare,
            'clinic_share_paisa' => $total - $doctorShare,
        ]);

        $method = self::weighted([
            PaymentMethod::Cash->value => 58,
            PaymentMethod::Bkash->value => 22,
            PaymentMethod::Nagad->value => 10,
            PaymentMethod::Card->value => 10,
        ]);

        $payment = Payment::query()->create([
            'invoice_id' => $invoice->id,
            'patient_id' => $patient->id,
            'receipt_number' => 'RCT-DEMO-'.$invoice->id,
            'method' => $method,
            'status' => PaymentTxnStatus::Succeeded,
            'amount_paisa' => $total,
            'gateway' => in_array($method, ['bkash', 'nagad'], true) ? PaymentGateway::from($method) : null,
            'gateway_txn_id' => in_array($method, ['bkash', 'nagad'], true) ? 'TXN'.$invoice->id : null,
            'gateway_payload' => [],
            'idempotency_key' => 'demo-'.$invoice->id,
            'paid_at' => $paidAt,
        ]);

        // A small stream of refunds, so the collection report has something to subtract.
        if (mt_rand(1, 100) <= 3) {
            Refund::query()->create([
                'payment_id' => $payment->id,
                'invoice_id' => $invoice->id,
                'amount_paisa' => (int) ($total / 2),
                'method' => $payment->method,
                'status' => RefundStatus::Processed,
                'reason_code' => RefundReason::DoctorAbsent,
                'gateway_payload' => [],
                'processed_at' => $paidAt->addDay(),
            ]);
        }
    }

    /** @param array<int, mixed> $options */
    private static function pick(array $options): mixed
    {
        return $options[mt_rand(0, count($options) - 1)];
    }

    /** @param array<string, int> $weights */
    private static function weighted(array $weights): string
    {
        $total = array_sum($weights);
        $roll = mt_rand(1, max(1, $total));

        foreach ($weights as $value => $weight) {
            $roll -= $weight;

            if ($roll <= 0) {
                return (string) $value;
            }
        }

        return (string) array_key_first($weights);
    }

    /**
     * @param  array<int, array{0: string|null, 1: string|null, 2: string, 3: int}>  $rows
     * @return array{0: string|null, 1: string|null, 2: string, 3: int}
     */
    private static function weightedRow(array $rows): array
    {
        $total = array_sum(array_column($rows, 3));
        $roll = mt_rand(1, max(1, $total));

        foreach ($rows as $row) {
            $roll -= $row[3];

            if ($roll <= 0) {
                return $row;
            }
        }

        return $rows[0];
    }

    /** Front-loaded 0..1: patients arrive early and taper, which is what a real waiting room looks like. */
    private static function skew(): float
    {
        return min(mt_rand(0, 1000) / 1000, mt_rand(0, 1000) / 1000);
    }
}
