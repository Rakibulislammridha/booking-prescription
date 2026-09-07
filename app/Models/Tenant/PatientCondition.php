<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use App\Domain\Patients\Enums\ConditionStatus;
use Carbon\CarbonImmutable;
use Database\Factories\Tenant\PatientConditionFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $patient_id
 * @property string|null $icd10_code
 * @property string $condition_name
 * @property ConditionStatus $status
 * @property CarbonImmutable|null $onset_date
 * @property CarbonImmutable|null $resolved_date
 * @property string|null $notes
 * @property int|null $recorded_by_user_id
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Patient $patient
 */
final class PatientCondition extends TenantModel
{
    /** @use HasFactory<PatientConditionFactory> */
    use HasFactory;

    protected static string $factory = PatientConditionFactory::class;

    protected static bool $audited = true;

    protected $table = 'patient_conditions';

    protected $fillable = ['patient_id', 'icd10_code', 'condition_name', 'status', 'onset_date', 'resolved_date', 'notes', 'recorded_by_user_id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => ConditionStatus::class,
            'onset_date' => 'immutable_date',
            'resolved_date' => 'immutable_date',
            'notes' => 'encrypted',
        ];
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

    /**
     * active + chronic (the ones safety checks read).
     *
     * @param  Builder<PatientCondition>  $query
     */
    public function scopeCurrent(Builder $query): void
    {
        $query->whereIn('status', [ConditionStatus::Active->value, ConditionStatus::Chronic->value]);
    }
}
