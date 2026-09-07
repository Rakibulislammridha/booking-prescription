<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use App\Domain\Prescription\Enums\VisitStatus;
use App\Domain\Prescription\Enums\VisitType;
use Carbon\CarbonImmutable;
use Database\Factories\Tenant\VisitFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * One clinical encounter — the mutable working record the doctor edits until the prescription is issued
 * (SCHEMA §3.4). Clinical writes are audited explicitly by PrescriptionAuditor (event-named rows, §6.6).
 *
 * @property int $id
 * @property string $public_id
 * @property int|null $appointment_id
 * @property int|null $serial_id
 * @property int|null $session_instance_id
 * @property int $patient_id
 * @property int $doctor_id
 * @property int $branch_id
 * @property VisitType $type
 * @property VisitStatus $status
 * @property CarbonImmutable $started_at
 * @property CarbonImmutable|null $ended_at
 * @property array<int, array<string, mixed>> $chief_complaints
 * @property string|null $examination_findings
 * @property array<int, array<string, mixed>> $diagnoses
 * @property string|null $private_notes
 * @property CarbonImmutable|null $follow_up_on
 * @property string|null $follow_up_note
 * @property int|null $current_prescription_id
 * @property int|null $closed_by_user_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Patient $patient
 * @property-read Doctor $doctor
 * @property-read Branch $branch
 * @property-read Serial|null $serial
 * @property-read SessionInstance|null $sessionInstance
 * @property-read Prescription|null $currentPrescription
 * @property-read Prescription|null $draft
 * @property-read Collection<int, Prescription> $prescriptions
 * @property-read Collection<int, Vital> $vitals
 * @property-read Vital|null $latestVitals
 */
final class Visit extends TenantModel
{
    /** @use HasFactory<VisitFactory> */
    use HasFactory;

    protected static string $factory = VisitFactory::class;

    protected static bool $publicId = true;

    protected $table = 'visits';

    protected $fillable = [
        'appointment_id', 'serial_id', 'session_instance_id', 'patient_id', 'doctor_id', 'branch_id', 'type', 'status',
        'started_at', 'ended_at', 'chief_complaints', 'examination_findings', 'diagnoses', 'private_notes',
        'follow_up_on', 'follow_up_note', 'current_prescription_id', 'closed_by_user_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => VisitType::class,
            'status' => VisitStatus::class,
            'started_at' => 'immutable_datetime',
            'ended_at' => 'immutable_datetime',
            'chief_complaints' => 'array',
            'diagnoses' => 'array',
            'private_notes' => 'encrypted',
            'follow_up_on' => 'immutable_date',
        ];
    }

    /** @return BelongsTo<Patient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /** @return BelongsTo<Doctor, $this> */
    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }

    /** @return BelongsTo<Branch, $this> */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** @return BelongsTo<Serial, $this> */
    public function serial(): BelongsTo
    {
        return $this->belongsTo(Serial::class);
    }

    /** @return BelongsTo<SessionInstance, $this> */
    public function sessionInstance(): BelongsTo
    {
        return $this->belongsTo(SessionInstance::class);
    }

    /** @return BelongsTo<Prescription, $this> */
    public function currentPrescription(): BelongsTo
    {
        return $this->belongsTo(Prescription::class, 'current_prescription_id');
    }

    /** @return HasMany<Prescription, $this> */
    public function prescriptions(): HasMany
    {
        return $this->hasMany(Prescription::class);
    }

    /**
     * The one draft of this visit (partial unique index), if any.
     *
     * @return HasOne<Prescription, $this>
     */
    public function draft(): HasOne
    {
        return $this->hasOne(Prescription::class)->where('status', 'draft');
    }

    /** @return HasMany<Vital, $this> */
    public function vitals(): HasMany
    {
        return $this->hasMany(Vital::class);
    }

    /** @return HasOne<Vital, $this> */
    public function latestVitals(): HasOne
    {
        return $this->hasOne(Vital::class)->latestOfMany('recorded_at');
    }

    /** @return BelongsTo<User, $this> */
    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by_user_id');
    }

    /** @param  Builder<Visit>  $query */
    public function scopeOpen(Builder $query): void
    {
        $query->where('status', VisitStatus::Open->value);
    }

    /** The primary diagnosis code: first `final`, else first provisional, else null (PRESCRIPTION.md §3.5). */
    public function primaryDiagnosisCode(): ?string
    {
        $rows = $this->diagnoses;

        foreach (['final', 'provisional'] as $kind) {
            foreach ($rows as $dx) {
                if (($dx['kind'] ?? null) === $kind && ! empty($dx['icd10_code'])) {
                    return (string) $dx['icd10_code'];
                }
            }
        }

        return null;
    }

    /** @return list<string> every ICD-10 code on the pad */
    public function diagnosisCodes(): array
    {
        return array_values(array_unique(array_filter(array_map(fn ($dx) => isset($dx['icd10_code']) ? (string) $dx['icd10_code'] : null, $this->diagnoses))));
    }
}
