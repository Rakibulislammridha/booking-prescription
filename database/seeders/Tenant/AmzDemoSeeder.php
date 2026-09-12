<?php

declare(strict_types=1);

namespace Database\Seeders\Tenant;

use App\Domain\Clinic\Enums\Gender;
use App\Domain\Clinic\Enums\Role;
use App\Domain\Prescription\Actions\CreateDraftPrescription;
use App\Domain\Prescription\Actions\IssuePrescription;
use App\Domain\Prescription\Actions\SaveDraft;
use App\Domain\Prescription\Data\DraftPayload;
use App\Domain\Prescription\Data\IssueRequest;
use App\Domain\Prescription\Enums\VisitStatus;
use App\Domain\Prescription\Enums\VisitType;
use App\Domain\Scheduling\Enums\ScheduleMode;
use App\Domain\Scheduling\Enums\SessionStatus;
use App\Domain\Scheduling\Services\SessionMaterialiser;
use App\Domain\Serials\Actions\AllocateSerial;
use App\Domain\Serials\Actions\CheckInSerial;
use App\Domain\Serials\Actions\StartSession;
use App\Domain\Serials\Data\AllocationRequest;
use App\Domain\Serials\Enums\SerialPool;
use App\Domain\Serials\Enums\SerialSource;
use App\Domain\Shared\Actor;
use App\Models\Tenant\Branch;
use App\Models\Tenant\Department;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\DoctorPadSetting;
use App\Models\Tenant\DoctorProfile;
use App\Models\Tenant\DoctorSchedule;
use App\Models\Tenant\DoctorSpecialty;
use App\Models\Tenant\Patient;
use App\Models\Tenant\Prescription;
use App\Models\Tenant\SessionInstance;
use App\Models\Tenant\Specialty;
use App\Models\Tenant\User;
use App\Models\Tenant\Visit;
use App\Models\Tenant\Vital;
use App\Support\Clock;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * AMZ Hospital Ltd. — the reference clinic behind the pad redesign (BRIEF §5.A).
 *
 * The product owner photographed a real Bangladeshi chamber pad and asked for our printed prescription to look like
 * that rather than like a generic web header. This seeder is that pad, as data: Dr. Md. Mostakim Billah's nine
 * header lines in the clinic's maroon and near-black, the rule under them, and the three-column footer — hospital
 * mark and address, chamber hours, the number you ring for a serial. Everything in `letterhead()` below is a
 * transcription of the photograph, which is what makes it worth keeping: a designer change that stops reproducing
 * this pad is a regression somebody can see.
 *
 * (One transcription liberty: the real pad prints `THUSDAY`. The typo is the printer's; the data says THURSDAY.)
 *
 * Idempotent. Everything is keyed on a natural key — branch code, doctor slug, patient mobile, session date,
 * `client_event_id` for serials — so running it again on a clinic that already exists changes nothing and adds no
 * second prescription. It runs inside an existing tenant; `Database\Seeders\AmzTenantSeeder` is what provisions one.
 */
final class AmzDemoSeeder extends Seeder
{
    public const DOCTOR_SLUG = 'dr-mostakim-billah';

    public const DOCTOR_EMAIL = 'mostakim@amz.test';

    public const BRANCH_CODE = 'AMZ';

    public const ADDRESS = 'CHA-80/3, Shadhinota Sarani, Progati Sarani Rd, Uttar Badda, Dhaka-1212';

    public const HOTLINE = '+8801409961047';

    /** The same number as it is PRINTED on the pad — the column stores E.164, the paper spells it out. */
    public const HOTLINE_PRINTED = '+88 01409961047';

    public const SHORT_CODE = '10699';

    /** The two drugs on the demo prescription, as catalog generic slugs plus the shorthand the doctor would type. */
    private const RX = [
        ['slug' => 'esomeprazole', 'form' => 'cap', 'label' => '20', 'shorthand' => '1+0+0 30d bf'],
        ['slug' => 'paracetamol', 'form' => 'tab', 'label' => '500', 'shorthand' => '1+1+1 5d af'],
    ];

    /** Seeded patients: name, mobile, sex, year of birth, and the vitals the compounder recorded today. */
    private const PATIENTS = [
        ['name' => 'Rehana Begum', 'mobile' => '+8801711450001', 'gender' => 'female', 'born' => 1978, 'vitals' => ['bp_systolic' => 148, 'bp_diastolic' => 92, 'pulse_bpm' => 84, 'temperature_c' => 37.1, 'spo2_percent' => 97, 'weight_kg' => 71.0, 'height_cm' => 155]],
        ['name' => 'Mizanur Rahman', 'mobile' => '+8801711450002', 'gender' => 'male', 'born' => 1965, 'vitals' => ['bp_systolic' => 132, 'bp_diastolic' => 84, 'pulse_bpm' => 78, 'temperature_c' => 36.8, 'spo2_percent' => 98, 'weight_kg' => 68.5, 'height_cm' => 168]],
        ['name' => 'Sharmin Akter', 'mobile' => '+8801711450003', 'gender' => 'female', 'born' => 1994, 'vitals' => ['bp_systolic' => 118, 'bp_diastolic' => 76, 'pulse_bpm' => 92, 'temperature_c' => 38.4, 'spo2_percent' => 96, 'weight_kg' => 54.0, 'height_cm' => 158]],
        ['name' => 'Abdul Karim', 'mobile' => '+8801711450004', 'gender' => 'male', 'born' => 1951, 'vitals' => ['bp_systolic' => 156, 'bp_diastolic' => 96, 'pulse_bpm' => 71, 'temperature_c' => 36.6, 'spo2_percent' => 95, 'weight_kg' => 62.0, 'height_cm' => 164]],
    ];

    public function run(): void
    {
        $branch = $this->branch();
        $doctor = $this->doctor($branch);
        $this->pad($doctor);
        $this->schedules($doctor, $branch);

        $session = $this->todaysSession($doctor, $branch);
        $visits = $this->patients($doctor, $branch, $session);

        $this->prescription($doctor, $visits[0] ?? null);
    }

    private function branch(): Branch
    {
        return Branch::query()->updateOrCreate(
            ['code' => self::BRANCH_CODE],
            [
                'name' => 'AMZ Hospital Ltd.',
                'slug' => 'amz-hospital',
                'address' => self::ADDRESS,
                'phone' => self::HOTLINE,
                'is_main' => true,
                'is_active' => true,
                'settings' => ['token_slip_width_mm' => 80, 'display_mode' => ['voice' => true, 'languages' => ['bn', 'en']]],
            ],
        );
    }

    private function doctor(Branch $branch): Doctor
    {
        $department = Department::query()->updateOrCreate(['slug' => 'medicine'], ['name' => 'Medicine', 'name_bn' => 'মেডিসিন', 'sort_order' => 1]);
        $specialty = Specialty::query()->updateOrCreate(['slug' => 'medicine'], ['name' => 'Medicine', 'name_bn' => 'মেডিসিন', 'icon' => 'MedicalServices', 'sort_order' => 1]);
        $critical = Specialty::query()->updateOrCreate(['slug' => 'critical-care'], ['name' => 'Critical Care', 'name_bn' => 'নিবিড় পরিচর্যা', 'icon' => 'MonitorHeart', 'sort_order' => 2]);

        $user = User::query()->updateOrCreate(
            ['email' => self::DOCTOR_EMAIL],
            ['name' => 'Dr. Md. Mostakim Billah', 'mobile' => self::HOTLINE, 'password' => Hash::make('password'), 'default_branch_id' => $branch->id, 'locale' => 'en', 'is_active' => true, 'email_verified_at' => Clock::now()],
        );
        $user->syncRoles([Role::Doctor->value]);

        $doctor = Doctor::query()->updateOrCreate(
            ['slug' => self::DOCTOR_SLUG],
            [
                'user_id' => $user->id, 'name' => 'Dr. Md. Mostakim Billah', 'name_bn' => 'ডা. মো. মোস্তাকিম বিল্লাহ',
                'code' => 'MSB', 'gender' => Gender::Male, 'mobile' => self::HOTLINE, 'email' => self::DOCTOR_EMAIL,
                'department_id' => $department->id, 'is_active' => true, 'accepts_online_booking' => true, 'room_label' => 'Chamber 2',
            ],
        );

        DoctorProfile::query()->updateOrCreate(['doctor_id' => $doctor->id], [
            'degrees' => 'MBBS (Dhaka Medical College), MRCP (UK), FCPS (Medicine)',
            'degrees_bn' => 'এমবিবিএস (ঢাকা মেডিকেল কলেজ), এমআরসিপি (ইউকে), এফসিপিএস (মেডিসিন)',
            'bmdc_reg_no' => 'A79319',
            'designation' => 'Consultant, Internal Medicine & Critical Care Unit',
            'chamber_notes' => 'Saturday – Thursday 04 PM – 09 PM · Friday 09:30 AM – 12:30 PM',
            'languages' => ['bn', 'en'],
            'new_fee_paisa' => 150000, 'followup_fee_paisa' => 100000,
            'free_followup_within_days' => 15, 'followup_within_days' => 30,
        ]);

        foreach ([$specialty->id => true, $critical->id => false] as $specialtyId => $isPrimary) {
            DoctorSpecialty::query()->updateOrCreate(['doctor_id' => $doctor->id, 'specialty_id' => $specialtyId], ['is_primary' => $isPrimary]);
        }

        return $doctor;
    }

    /**
     * The photographed pad, transcribed: English-only (the paper is), narrow margins, and `letterhead` doing all
     * the work `header_html` used to fail to do.
     *
     * A4 rather than the A5 the photographed pad is printed on. The paper in the photo is blank — a doctor fills it
     * by hand — while ours arrives already carrying vitals, complaints, findings, a diagnosis, the Rx, advice and a
     * follow-up under a nine-line letterhead and a three-column footer: 227 mm of content, which is 41 mm more than
     * A5 holds. Pushing it onto A5 would only reproduce the two-page sheet this release exists to fix. It is one
     * field in the designer for a doctor who prints short scripts and wants the smaller paper back.
     */
    private function pad(Doctor $doctor): DoctorPadSetting
    {
        /** @var DoctorPadSetting $pad */
        $pad = DoctorPadSetting::query()->firstOrNew(['doctor_id' => $doctor->id], DoctorPadSetting::defaults());

        $pad->fill(DoctorPadSetting::defaults())->fill([
            'paper_size' => 'A4',
            'orientation' => 'portrait',
            'letterhead_enabled' => true,
            'preprinted_mode' => false,
            'margins' => ['top' => 14, 'right' => 14, 'bottom' => 12, 'left' => 14],
            'font_family' => 'Inter',
            'font_size_pt' => 10.5,
            'default_language' => 'en',
            'show_qr' => true,
            'show_vitals' => true,
            'show_drug_info_url' => true,
            'letterhead' => self::letterhead(),
        ])->save();

        return $pad;
    }

    /**
     * `doctor_pad_settings.letterhead` for the reference pad — the contract shape
     * (App\Domain\Prescription\Data\Letterhead), read straight off the photograph.
     *
     * @return array<string, mixed>
     */
    public static function letterhead(): array
    {
        $line = fn (string $text, string $color, string $weight, float $size): array => [
            'text' => $text, 'text_bn' => null, 'color' => $color, 'weight' => $weight,
            'size' => $size, 'transform' => 'uppercase', 'align' => null,
        ];

        return [
            'accent_color' => '#B03A2E',
            'text_color' => '#1A1A1A',
            'muted_color' => '#666666',
            'header' => [
                'align' => 'left',
                'lines' => [
                    $line('Dr. Md. Mostakim Billah', 'accent', 'bold', 1.5),
                    $line('MBBS (Dhaka Medical College)', 'text', 'normal', 0.92),
                    $line('MRCP (UK), FCPS (Medicine)', 'text', 'bold', 0.92),
                    $line('Medicine Specialist', 'accent', 'normal', 0.95),
                    $line('Dhaka Medical College Hospital', 'text', 'normal', 0.88),
                    $line('Consultant', 'accent', 'normal', 0.9),
                    $line('Internal Medicine & Critical Care Unit', 'text', 'normal', 0.88),
                    $line('AMZ Hospital Ltd.', 'text', 'normal', 0.88),
                    $line('BMDC No: A79319', 'text', 'normal', 0.84),   // near-black like the reference pad, not muted
                ],
                'rule' => true,
            ],
            'footer' => [
                'columns' => [
                    [
                        'align' => 'left',
                        'logo' => true,
                        'lines' => [
                            $line('AMZ Hospital Ltd.', 'accent', 'bold', 0.95),
                            ['text' => 'For Amazing Care', 'text_bn' => null, 'color' => 'muted', 'weight' => 'normal', 'size' => 0.78, 'transform' => 'none', 'align' => null],
                            $line(self::ADDRESS, 'muted', 'normal', 0.72),
                        ],
                    ],
                    [
                        'align' => 'center',
                        'logo' => false,
                        'lines' => [
                            $line('Chamber Time', 'text', 'bold', 0.88),
                            $line('Saturday - Thursday', 'muted', 'normal', 0.78),
                            $line('04 PM-09 PM', 'text', 'normal', 0.82),
                            $line('Friday', 'muted', 'normal', 0.78),
                            $line('09:30AM-12:30PM', 'text', 'normal', 0.82),
                        ],
                    ],
                    [
                        'align' => 'right',
                        'logo' => false,
                        'lines' => [
                            $line('Call For Serial', 'text', 'bold', 0.88),
                            $line(self::HOTLINE_PRINTED, 'accent', 'normal', 0.88),
                            $line('or', 'muted', 'normal', 0.75),
                            $line(self::SHORT_CODE, 'accent', 'bold', 0.95),
                        ],
                    ],
                ],
                'rule' => true,
            ],
        ];
    }

    /** The chamber hours off the pad: session B Saturday–Thursday 16:00–21:00, session A on Friday 09:30–12:30. */
    private function schedules(Doctor $doctor, Branch $branch): void
    {
        $effectiveFrom = Clock::today()->subMonth()->toDateString();
        $rows = [];

        foreach ([6, 0, 1, 2, 3, 4] as $weekday) {          // Saturday … Thursday (0 = Sunday, SCHEMA §3.3)
            $rows[] = [$weekday, 'B', 'Evening', '16:00:00', '21:00:00'];
        }

        $rows[] = [5, 'A', 'Morning', '09:30:00', '12:30:00'];   // Friday

        foreach ($rows as [$weekday, $code, $label, $start, $end]) {
            DoctorSchedule::query()->updateOrCreate(
                ['doctor_id' => $doctor->id, 'branch_id' => $branch->id, 'weekday' => $weekday, 'session_code' => $code, 'effective_from' => $effectiveFrom],
                [
                    'session_label' => $label, 'start_time' => $start, 'end_time' => $end, 'mode' => ScheduleMode::Serial,
                    'slot_minutes' => null, 'max_serials' => 40, 'online_quota' => 15, 'counter_quota' => 20, 'buffer_quota' => 5,
                    'avg_consult_minutes' => 7, 'fee_new_paisa' => 150000, 'fee_followup_paisa' => 100000,
                    'works_on_holidays' => false, 'effective_to' => null, 'is_active' => true,
                ],
            );
        }
    }

    /** Today's chamber, materialised through the real planner and opened so the desk board has something on it. */
    private function todaysSession(Doctor $doctor, Branch $branch): ?SessionInstance
    {
        $session = app(SessionMaterialiser::class)->ensureDay($branch->id, $doctor->id, Clock::today())->first();

        if ($session !== null && $session->status === SessionStatus::Scheduled) {
            $session = app(StartSession::class)->handle($session, Actor::system());
        }

        return $session;
    }

    /**
     * Four patients, each with today's visit and the reading the compounder took. When a session exists they also
     * hold a real serial, allocated through the engine with a fixed `client_event_id` so a re-run issues no second
     * number.
     *
     * @return list<Visit>
     */
    private function patients(Doctor $doctor, Branch $branch, ?SessionInstance $session): array
    {
        $actor = Actor::system();
        $visits = [];

        foreach (self::PATIENTS as $index => $row) {
            $patient = Patient::query()->updateOrCreate(
                ['mobile' => $row['mobile']],
                [
                    'name' => $row['name'], 'is_mobile_owner' => true, 'gender' => Gender::from($row['gender']),
                    'dob' => $row['born'].'-01-15', 'dob_is_estimated' => true, 'address' => self::ADDRESS,
                    'district' => 'Dhaka', 'preferred_language' => 'bn', 'registered_branch_id' => $branch->id,
                    'source' => 'counter', 'is_active' => true,
                ],
            );

            $serial = null;

            if ($session !== null) {
                $serial = app(AllocateSerial::class)(new AllocationRequest(
                    sessionInstanceId: $session->id,
                    pool: SerialPool::Counter,
                    source: SerialSource::Counter,
                    patientId: $patient->id,
                    clientEventId: self::clientEventId($index),
                ));

                if ($serial->status->value === 'booked') {
                    $serial = app(CheckInSerial::class)->handle($serial, $actor);
                }
            }

            $visit = Visit::query()->firstOrCreate(
                ['patient_id' => $patient->id, 'doctor_id' => $doctor->id, 'started_at' => Clock::now()->startOfDay()->addHours(16 + $index)],
                [
                    'branch_id' => $branch->id, 'serial_id' => $serial?->id, 'session_instance_id' => $session?->id,
                    'type' => VisitType::Opd, 'status' => VisitStatus::Open,
                    'chief_complaints' => $index === 0
                        ? [['text' => 'Burning epigastric pain', 'text_bn' => null, 'duration' => '2w', 'sort' => 0], ['text' => 'Fever', 'text_bn' => null, 'duration' => '3d', 'sort' => 1]]
                        : [],
                    'examination_findings' => $index === 0 ? 'Epigastric tenderness, no guarding' : null,
                    'diagnoses' => $index === 0 ? [['icd10_code' => 'K21.0', 'title' => 'Gastro-oesophageal reflux disease with oesophagitis', 'kind' => 'provisional', 'sort' => 0]] : [],
                ],
            );

            Vital::query()->firstOrCreate(
                ['visit_id' => $visit->id],
                $row['vitals'] + [
                    'patient_id' => $patient->id,
                    'recorded_at' => $visit->started_at,
                    'bmi' => Vital::computeBmi((float) $row['vitals']['weight_kg'], (float) $row['vitals']['height_cm']),
                ],
            );

            $visits[] = $visit;
        }

        return $visits;
    }

    /**
     * One issued prescription with two drugs, written through the real draft → issue path so what it prints is a
     * genuine frozen snapshot rather than hand-built JSON. Skipped when this visit already has one (the seeder is
     * re-runnable) and when the shared catalog has not been seeded on this machine — nothing else in the clinic
     * depends on it, and `AmzDemoSeederTest` is what asserts the prescription exists where the catalog does.
     */
    private function prescription(Doctor $doctor, ?Visit $visit): void
    {
        if ($visit === null || $doctor->user_id === null) {
            return;
        }

        if (Prescription::query()->where('visit_id', $visit->id)->where('status', 'issued')->exists()) {
            return;
        }

        $items = [];

        foreach (self::RX as $index => $line) {
            $drug = self::presentation($line['slug'], $line['form'], $line['label']);

            if ($drug === null) {
                return;     // no shared catalog on this machine; the rest of the clinic stands on its own
            }

            $items[] = ['key' => 'amz'.($index + 1), 'sort_order' => $index, 'drug' => $drug, 'shorthand' => $line['shorthand'], 'safety_overrides' => []];
        }

        $actor = Actor::user($doctor->user_id);
        $draft = app(CreateDraftPrescription::class)->handle($visit, $doctor, $actor);

        $payload = DraftPayload::fromArray([
            'language' => 'en',
            'items' => $items,
            'investigations' => [],
            'advice' => [
                ['key' => 'a1', 'text' => 'Avoid spicy and oily food', 'text_bn' => 'ঝাল ও তেলযুক্ত খাবার এড়িয়ে চলুন', 'sort_order' => 0],
                ['key' => 'a2', 'text' => 'Take plenty of water', 'text_bn' => 'প্রচুর পানি পান করুন', 'sort_order' => 1],
            ],
            'referrals' => [],
            'follow_up_days' => 14,
            // No draft booking: the follow-up date belongs on the paper, but a seeder must not reach into next
            // month's serial pools to reserve a number nobody asked for.
            'create_booking' => false,
        ]);

        $draft = app(SaveDraft::class)->handle($draft, $payload, $actor)->model;

        app(IssuePrescription::class)->handle($draft->fresh() ?? $draft, new IssueRequest(language: 'en'), $actor);
    }

    /**
     * First active presentation of a catalog generic, as the draft payload wants it. A read on the SELECT-only
     * catalog connection, which is exactly what the writer does when a doctor picks a drug.
     *
     * @return array{generic_id: int, brand_id: int|null, strength_id: int}|null
     */
    private static function presentation(string $genericSlug, string $formCode, string $labelLike): ?array
    {
        $row = DB::connection('catalog')->table('strengths')
            ->join('generics', 'generics.id', '=', 'strengths.generic_id')
            ->join('dosage_forms', 'dosage_forms.id', '=', 'strengths.dosage_form_id')
            ->where('generics.slug', $genericSlug)
            ->where('dosage_forms.code', $formCode)
            ->where('strengths.strength_label', 'ILIKE', '%'.$labelLike.'%')
            ->where('strengths.is_active', true)
            ->orderBy('strengths.id')
            ->first(['strengths.id as strength_id', 'strengths.brand_id', 'strengths.generic_id']);

        return $row === null ? null : [
            'generic_id' => (int) $row->generic_id,
            'brand_id' => $row->brand_id === null ? null : (int) $row->brand_id,
            'strength_id' => (int) $row->strength_id,
        ];
    }

    /** A stable 26-character idempotency key per seeded serial, so re-running allocates nothing new. */
    private static function clientEventId(int $index): string
    {
        return str_pad('01AMZSEED'.($index + 1), 26, '0');
    }
}
