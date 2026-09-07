<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use App\Domain\Patients\Enums\PatientRelation as RelationType;
use Database\Factories\Tenant\PatientRelationFactory;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Family grouping (SCHEMA §3.2): the dependent is the primary's `relation` (spouse, child, …).
 *
 * @property int $id
 * @property int $primary_patient_id
 * @property int $dependent_patient_id
 * @property RelationType $relation
 * @property-read Patient $primary
 * @property-read Patient $dependent
 */
final class PatientRelation extends TenantModel
{
    /** @use HasFactory<PatientRelationFactory> */
    use HasFactory;

    protected static string $factory = PatientRelationFactory::class;

    protected static bool $audited = true;

    protected $table = 'patient_relations';

    protected $fillable = ['primary_patient_id', 'dependent_patient_id', 'relation'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['relation' => RelationType::class];
    }

    /**
     * Lets AuditRecorder file the row under the dependent ("who accessed this patient's data").
     *
     * @return Attribute<int, never>
     */
    protected function patientId(): Attribute
    {
        return Attribute::get(fn (): int => (int) $this->dependent_patient_id);
    }

    /** @return BelongsTo<Patient, $this> */
    public function primary(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'primary_patient_id');
    }

    /** @return BelongsTo<Patient, $this> */
    public function dependent(): BelongsTo
    {
        return $this->belongsTo(Patient::class, 'dependent_patient_id');
    }
}
