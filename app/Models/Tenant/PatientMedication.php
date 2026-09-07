<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use App\Domain\Patients\Enums\MedicationSource;
use Carbon\CarbonImmutable;
use Database\Factories\Tenant\PatientMedicationFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Current long-term medication list; feeds interaction / duplicate checks (SCHEMA §3.2).
 *
 * @property int $id
 * @property int $patient_id
 * @property int|null $generic_id
 * @property int|null $brand_id
 * @property int|null $custom_brand_id
 * @property string $generic_name
 * @property string|null $brand_name
 * @property string|null $dose_text
 * @property MedicationSource $source
 * @property int|null $prescription_item_id
 * @property CarbonImmutable|null $started_on
 * @property CarbonImmutable|null $ended_on
 * @property bool $is_active
 * @property string|null $notes
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 * @property-read Patient $patient
 */
final class PatientMedication extends TenantModel
{
    /** @use HasFactory<PatientMedicationFactory> */
    use HasFactory;

    protected static string $factory = PatientMedicationFactory::class;

    protected static bool $audited = true;

    protected $table = 'patient_medications';

    protected $fillable = [
        'patient_id', 'generic_id', 'brand_id', 'custom_brand_id', 'generic_name', 'brand_name', 'dose_text', 'source',
        'prescription_item_id', 'started_on', 'ended_on', 'is_active', 'notes',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'generic_id' => 'integer',
            'brand_id' => 'integer',
            'custom_brand_id' => 'integer',
            'source' => MedicationSource::class,
            'prescription_item_id' => 'integer',
            'started_on' => 'immutable_date',
            'ended_on' => 'immutable_date',
            'is_active' => 'boolean',
            'notes' => 'encrypted',
        ];
    }

    /** @return BelongsTo<Patient, $this> */
    public function patient(): BelongsTo
    {
        return $this->belongsTo(Patient::class);
    }

    /** @param  Builder<PatientMedication>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
