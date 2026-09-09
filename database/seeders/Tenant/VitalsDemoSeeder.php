<?php

declare(strict_types=1);

namespace Database\Seeders\Tenant;

use App\Domain\Clinic\Enums\Role;
use App\Domain\Prescription\Enums\VisitStatus;
use App\Domain\Serials\Enums\SerialStatus;
use App\Models\Tenant\Patient;
use App\Models\Tenant\Serial;
use App\Models\Tenant\User;
use App\Models\Tenant\Visit;
use App\Models\Tenant\Vital;
use App\Support\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Vitals for the demo clinic — the readings BRIEF §5.G.2 says the compounder takes before the doctor, so that the
 * patient record's trend charts (BRIEF §5.H) have a trend to draw instead of an empty panel on every patient.
 *
 * Two passes:
 *
 *   HISTORY — for the patients with the deepest visit history, a series of readings spread across the whole seeded
 *   window (evenly, by striding their visits, so a chart is a line over weeks rather than a cluster on one day).
 *   Each patient gets a fixed height and one of four clinical archetypes, and every value follows from that: BMI is
 *   computed from the weight and height actually recorded (Vital::computeBmi, never invented), diastolic follows
 *   systolic, pulse rises with a fever, a hypertensive's pressure drifts down as treatment works, a diabetic's
 *   glucose is recorded every time. A minority of readings are deliberately out of range — a spike in pressure, a
 *   fever, a dip in SpO2 — because a chart of nothing but normal values proves nothing about the chart.
 *
 *   TODAY — half of the patients who are checked in or with the doctor right now get a visit and a reading from the
 *   compounder; the other half get none. That is what makes today's reception board show both states of the vitals
 *   indicator, and it exercises "recorded, awaiting doctor review" (today) against "reviewed" (the closed history).
 *
 * Readings are written straight through the model rather than through RecordVitals, because that action stamps
 * `recorded_at = now()` by design and this seeder's whole point is history. Everything else it does — the BMI, the
 * compounder as `recorded_by_user_id` — is exactly what the action would have written.
 *
 * Deterministic (each patient's series is seeded from their own id, so a re-run after a partial seed produces the
 * same clinic), idempotent (a visit that already has a reading is skipped) and bounded: at most PATIENTS patients
 * and READINGS readings each.
 */
final class VitalsDemoSeeder extends Seeder
{
    /** Patients given a history, most-visited first. */
    private const PATIENTS = 150;

    /** Readings per patient — a year of quarterly follow-ups, or ten visits of a busy 8-week demo window. */
    private const READINGS = 10;

    private const SEED = 20260909;

    private const ARCHETYPE_HEALTHY = 'healthy';

    private const ARCHETYPE_HYPERTENSIVE = 'hypertensive';

    private const ARCHETYPE_DIABETIC = 'diabetic';

    private const ARCHETYPE_RESPIRATORY = 'respiratory';

    /**
     * What the last run wrote, for a caller that has somewhere to print it. The seeder does not print for itself:
     * ProvisionTenant runs the demo seeders straight from the container, with no console command behind them.
     *
     * @var array{history: int, today: int}
     */
    public array $written = ['history' => 0, 'today' => 0];

    public function run(): void
    {
        $compounder = $this->compounder();

        $this->written = [
            'history' => $this->seedHistory($compounder),
            'today' => $this->seedToday($compounder),
        ];
    }

    /**
     * The person the readings are attributed to: the demo's compounder, else any receptionist (the role that holds
     * `prescriptions.vitals.record` at the desk). Null is survivable — the screens show "—" for the recorder.
     */
    private function compounder(): ?User
    {
        return User::query()->where('email', 'compounder@demo.test')->first()
            ?? User::query()->whereHas('roles', fn ($q) => $q->where('name', Role::Receptionist->value))->orderBy('id')->first();
    }

    private function seedHistory(?User $compounder): int
    {
        /** @var array<int, int> $patientIds */
        $patientIds = DB::connection('pgsql')->table('visits')
            ->select('patient_id')
            ->selectRaw('count(*) as visits')
            ->groupBy('patient_id')
            ->orderByDesc('visits')
            ->orderBy('patient_id')
            ->limit(self::PATIENTS)
            ->pluck('patient_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        if ($patientIds === []) {
            return 0;
        }

        $patients = Patient::query()->whereIn('id', $patientIds)->get()->keyBy('id');
        $written = 0;

        foreach ($patientIds as $patientId) {
            $patient = $patients->get($patientId);

            if ($patient === null) {
                continue;
            }

            /** @var EloquentCollection<int, Visit> $visits */
            $visits = Visit::query()->where('patient_id', $patientId)->orderBy('started_at')->orderBy('id')->get();
            $written += $this->seriesFor($patient, $this->stride($visits), $compounder);
        }

        return $written;
    }

    /**
     * Ten readings evenly spread over the patient's whole history rather than the last ten visits: a trend chart is
     * about the months, and a patient who comes weekly would otherwise show a fortnight.
     *
     * @param  EloquentCollection<int, Visit>  $visits
     * @return array<int, Visit>
     */
    private function stride(EloquentCollection $visits): array
    {
        $count = $visits->count();

        if ($count <= self::READINGS) {
            return $visits->all();
        }

        $step = $count / self::READINGS;
        $picked = [];

        for ($i = 0; $i < self::READINGS; $i++) {
            $visit = $visits->get((int) floor($i * $step));

            if ($visit !== null) {
                $picked[] = $visit;
            }
        }

        return $picked;
    }

    /** @param  array<int, Visit>  $visits */
    private function seriesFor(Patient $patient, array $visits, ?User $compounder): int
    {
        if ($visits === []) {
            return 0;
        }

        $already = DB::connection('pgsql')->table('vitals')
            ->whereIn('visit_id', array_map(static fn (Visit $v): int => $v->id, $visits))
            ->pluck('visit_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        mt_srand(self::SEED + $patient->id);

        $archetype = $this->archetype($patient->id);
        $height = $this->height($patient);
        $weight = $this->startingWeight($patient, $height);
        $trend = match ($archetype) {
            self::ARCHETYPE_HYPERTENSIVE, self::ARCHETYPE_DIABETIC => -0.35,   // treatment, and it shows
            self::ARCHETYPE_RESPIRATORY => 0.05,
            default => 0.12,
        };

        $written = 0;

        foreach ($visits as $index => $visit) {
            $weight = round($weight + $trend + mt_rand(-4, 4) / 10, 1);

            if (in_array($visit->id, $already, true)) {
                continue;   // idempotent: this visit already carries a reading
            }

            // The compounder takes the readings while the patient waits, a few minutes before the doctor starts.
            $recordedAt = $visit->started_at->subMinutes(mt_rand(4, 22));
            $closed = $visit->status === VisitStatus::Closed;

            $this->write($visit, $patient, $compounder, $recordedAt, $archetype, $height, $weight, $index, [
                // History has been through the doctor: reviewed, and now and then corrected by them.
                'edited_by_doctor' => $closed && mt_rand(1, 100) <= 7,
                'reviewed_by_doctor_at' => $closed ? $recordedAt->addMinutes(mt_rand(6, 28)) : null,
            ]);

            $written++;
        }

        return $written;
    }

    /**
     * The patients in the waiting room right now. Half of them have been through the compounder and half have not,
     * which is the pair of states the reception board's indicator exists to tell apart.
     */
    private function seedToday(?User $compounder): int
    {
        $today = Clock::today();

        /** @var EloquentCollection<int, Serial> $serials */
        $serials = Serial::query()
            ->whereIn('status', [SerialStatus::CheckedIn->value, SerialStatus::InConsultation->value])
            ->whereHas('sessionInstance', fn ($q) => $q->whereDate('session_date', $today->toDateString()))
            ->whereNotNull('patient_id')
            ->with('sessionInstance')
            ->orderBy('id')
            ->get();

        $patients = Patient::query()->whereIn('id', $serials->pluck('patient_id')->filter()->unique()->all())->get()->keyBy('id');
        $written = 0;

        foreach ($serials->values() as $index => $serial) {
            $patient = $patients->get($serial->patient_id);
            $session = $serial->sessionInstance;

            if ($index % 2 === 1 || $patient === null) {
                continue;   // every other arrival is still waiting for the compounder
            }

            $visit = Visit::query()->where('serial_id', $serial->id)->first();

            if ($visit === null) {
                $visit = Visit::query()->create([
                    'serial_id' => $serial->id,
                    'session_instance_id' => $serial->session_instance_id,
                    'appointment_id' => $serial->appointment_id,
                    'patient_id' => $patient->id,
                    'doctor_id' => $session->doctor_id,
                    'branch_id' => $session->branch_id,
                    'type' => 'opd',
                    'status' => VisitStatus::Open,
                    'started_at' => $serial->checked_in_at ?? CarbonImmutable::now(),
                ]);
            }

            if (DB::connection('pgsql')->table('vitals')->where('visit_id', $visit->id)->exists()) {
                continue;
            }

            mt_srand(self::SEED + $patient->id);
            $height = $this->height($patient);

            $this->write(
                $visit, $patient, $compounder,
                ($serial->checked_in_at ?? CarbonImmutable::now())->addMinutes(mt_rand(2, 9)),
                $this->archetype($patient->id), $height, $this->startingWeight($patient, $height), 0,
                // Taken minutes ago: the doctor has not signed off yet, which is the state the desk screen shows.
                ['edited_by_doctor' => false, 'reviewed_by_doctor_at' => null],
            );

            $written++;
        }

        return $written;
    }

    /**
     * One reading, internally consistent: diastolic follows systolic, pulse follows the temperature, BMI is
     * computed from the very weight and height being written, and the values a clinic would not have measured are
     * left null rather than filled in for the sake of a fuller row.
     *
     * @param  array{edited_by_doctor: bool, reviewed_by_doctor_at: CarbonImmutable|null}  $review
     */
    private function write(Visit $visit, Patient $patient, ?User $compounder, CarbonImmutable $recordedAt, string $archetype, float $height, float $weight, int $index, array $review): void
    {
        $feverChance = $archetype === self::ARCHETYPE_RESPIRATORY ? 25 : 15;
        $fever = mt_rand(1, 100) <= $feverChance;
        $spike = mt_rand(1, 100) <= 10;

        [$systolic, $diastolic] = $this->pressure($archetype, $index, $spike);
        $temperature = $fever ? round(37.9 + mt_rand(0, 14) / 10, 1) : round(36.5 + mt_rand(0, 6) / 10, 1);
        $pulse = min(140, 68 + mt_rand(0, 14) + ($fever ? mt_rand(16, 28) : 0) + ($archetype === self::ARCHETYPE_HYPERTENSIVE ? 4 : 0));
        $spo2 = match (true) {
            $archetype === self::ARCHETYPE_RESPIRATORY => mt_rand(1, 100) <= 25 ? mt_rand(91, 93) : mt_rand(94, 97),
            $fever => mt_rand(95, 98),
            default => mt_rand(96, 99),
        };

        Vital::query()->create([
            'visit_id' => $visit->id,
            'patient_id' => $patient->id,
            'recorded_by_user_id' => $compounder?->id,
            'recorded_at' => $recordedAt,
            'bp_systolic' => $systolic,
            'bp_diastolic' => $diastolic,
            'pulse_bpm' => $pulse,
            'temperature_c' => $temperature,
            'spo2_percent' => $spo2,
            'respiratory_rate' => $fever || $archetype === self::ARCHETYPE_RESPIRATORY ? mt_rand(20, 26) : (mt_rand(1, 100) <= 40 ? mt_rand(14, 18) : null),
            'weight_kg' => $weight,
            'height_cm' => $height,
            'bmi' => Vital::computeBmi($weight, $height),
            'blood_glucose_mgdl' => $archetype === self::ARCHETYPE_DIABETIC ? mt_rand(118, 214) : (mt_rand(1, 100) <= 12 ? mt_rand(80, 118) : null),
            'notes' => $this->note($archetype, $fever, $spike),
            'edited_by_doctor' => $review['edited_by_doctor'],
            'reviewed_by_doctor_at' => $review['reviewed_by_doctor_at'],
        ]);
    }

    /** @return array{0: int, 1: int} systolic, diastolic — a plausible pulse pressure, never an invented pair */
    private function pressure(string $archetype, int $index, bool $spike): array
    {
        $systolic = match ($archetype) {
            // Treated hypertension coming down over the series, from "needs treatment" to "controlled".
            self::ARCHETYPE_HYPERTENSIVE => 152 - $index * 2 + mt_rand(-4, 4),
            self::ARCHETYPE_DIABETIC => 132 + mt_rand(-6, 8),
            self::ARCHETYPE_RESPIRATORY => 118 + mt_rand(-6, 6),
            default => 114 + mt_rand(-6, 10),
        };

        if ($spike) {
            $systolic += mt_rand(18, 34);   // the day the chart is supposed to show something
        }

        $systolic = max(92, min(206, $systolic));
        $diastolic = max(58, min(126, (int) round($systolic * 0.63) + mt_rand(-4, 4)));

        return [$systolic, $diastolic];
    }

    private function archetype(int $patientId): string
    {
        return match (true) {
            $patientId % 20 < 10 => self::ARCHETYPE_HEALTHY,
            $patientId % 20 < 15 => self::ARCHETYPE_HYPERTENSIVE,
            $patientId % 20 < 18 => self::ARCHETYPE_DIABETIC,
            default => self::ARCHETYPE_RESPIRATORY,
        };
    }

    /** Fixed per patient for the life of the record — an adult does not change height between visits, and BMI would lie if they did. */
    private function height(Patient $patient): float
    {
        $female = $patient->gender?->value === 'female';
        $child = ($patient->age_years ?? 30) < 16;

        if ($child) {
            return round(96 + max(0, (int) ($patient->age_years ?? 8)) * 6 + ($patient->id % 7), 1);
        }

        return round(($female ? 148 : 160) + ($patient->id % ($female ? 16 : 18)), 1);
    }

    private function startingWeight(Patient $patient, float $height): float
    {
        $bmi = 19 + ($patient->id % 13);   // 19–31: normal through overweight, the spread of a real waiting room

        return round($bmi * (($height / 100) ** 2), 1);
    }

    private function note(string $archetype, bool $fever, bool $spike): ?string
    {
        return match (true) {
            $spike && $archetype === self::ARCHETYPE_HYPERTENSIVE => 'রক্তচাপ বেশি — ১০ মিনিট পর আবার মাপা হয়েছে',
            $fever => 'জ্বর নিয়ে এসেছেন',
            $archetype === self::ARCHETYPE_DIABETIC => 'খালি পেটে গ্লুকোজ',
            default => null,
        };
    }
}
