<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use App\Domain\Patients\Enums\AllergenType;
use App\Domain\Patients\Enums\AllergySeverity;
use Carbon\CarbonImmutable;
use Database\Factories\Tenant\PatientAllergyFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $patient_id
 * @property AllergenType $allergen_type
 * @property int|null $generic_id
 * @property int|null $allergy_class_id
 * @property string $allergen_name
 * @property string|null $reaction
 * @property AllergySeverity $severity
 * @property string|null $notes
 * @property bool $is_active
 * @property int|null $recorded_by_user_id
 * @property int|null $verified_by_doctor_id
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Patient $patient
 */
final class PatientAllergy extends TenantModel
{
    /** @use HasFactory<PatientAllergyFactory> */
    use HasFactory;

    protected static string $factory = PatientAllergyFactory::class;

    protected static bool $audited = true;

    protected $table = 'patient_allergies';

    protected $fillable = [
        'patient_id', 'allergen_type', 'generic_id', 'allergy_class_id', 'allergen_name', 'reaction', 'severity', 'notes',
        'is_active', 'recorded_by_user_id', 'verified_by_doctor_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'allergen_type' => AllergenType::class,
            'generic_id' => 'integer',
            'allergy_class_id' => 'integer',
            'severity' => AllergySeverity::class,
            'notes' => 'encrypted',
            'is_active' => 'boolean',
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

    /** @return BelongsTo<Doctor, $this> */
    public function verifiedBy(): BelongsTo
    {
        return $this->belongsTo(Doctor::class, 'verified_by_doctor_id');
    }

    /** @param  Builder<PatientAllergy>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
