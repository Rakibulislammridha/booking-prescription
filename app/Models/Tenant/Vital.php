<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Carbon\CarbonImmutable;
use Database\Factories\Tenant\VitalFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Vitals recorded by the compounder before the doctor (SCHEMA §3.4); several rows per visit allowed. BMI is
 * computed by the app on write (RecordVitals / UpdateVitals). Audited explicitly by PrescriptionAuditor.
 *
 * @property int $id
 * @property int $visit_id
 * @property int $patient_id
 * @property int|null $recorded_by_user_id
 * @property CarbonImmutable $recorded_at
 * @property int|null $bp_systolic
 * @property int|null $bp_diastolic
 * @property int|null $pulse_bpm
 * @property float|null $temperature_c
 * @property int|null $spo2_percent
 * @property int|null $respiratory_rate
 * @property float|null $weight_kg
 * @property float|null $height_cm
 * @property float|null $bmi
 * @property int|null $blood_glucose_mgdl
 * @property string|null $notes
 * @property bool $edited_by_doctor
 * @property CarbonImmutable|null $reviewed_by_doctor_at
 * @property-read Visit $visit
 * @property-read User|null $recordedBy
 */
final class Vital extends TenantModel
{
    /** @use HasFactory<VitalFactory> */
    use HasFactory;

    public const MEASUREMENTS = ['bp_systolic', 'bp_diastolic', 'pulse_bpm', 'temperature_c', 'spo2_percent', 'respiratory_rate', 'weight_kg', 'height_cm', 'blood_glucose_mgdl', 'notes'];

    protected static string $factory = VitalFactory::class;

    protected $table = 'vitals';

    protected $fillable = [
        'visit_id', 'patient_id', 'recorded_by_user_id', 'recorded_at', 'bp_systolic', 'bp_diastolic', 'pulse_bpm', 'temperature_c',
        'spo2_percent', 'respiratory_rate', 'weight_kg', 'height_cm', 'bmi', 'blood_glucose_mgdl', 'notes', 'edited_by_doctor',
        'reviewed_by_doctor_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'recorded_at' => 'immutable_datetime',
            'bp_systolic' => 'integer',
            'bp_diastolic' => 'integer',
            'pulse_bpm' => 'integer',
            'temperature_c' => 'float',
            'spo2_percent' => 'integer',
            'respiratory_rate' => 'integer',
            'weight_kg' => 'float',
            'height_cm' => 'float',
            'bmi' => 'float',
            'blood_glucose_mgdl' => 'integer',
            'edited_by_doctor' => 'boolean',
            'reviewed_by_doctor_at' => 'immutable_datetime',
        ];
    }

    /** `weight_kg / (height_cm/100)^2`, 1 dp; null when either is missing (PRESCRIPTION.md §4.2). */
    public static function computeBmi(?float $weightKg, ?float $heightCm): ?float
    {
        if ($weightKg === null || $heightCm === null || $weightKg <= 0 || $heightCm <= 0) {
            return null;
        }

        return round($weightKg / (($heightCm / 100) ** 2), 1);
    }

    /** @return BelongsTo<Visit, $this> */
    public function visit(): BelongsTo
    {
        return $this->belongsTo(Visit::class);
    }

    /** @return BelongsTo<Patient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /** @return BelongsTo<User, $this> */
    public function recordedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by_user_id');
    }
}
