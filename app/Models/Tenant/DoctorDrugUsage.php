<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Carbon\CarbonImmutable;
use Database\Factories\Tenant\DoctorDrugUsageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Monthly learning rows (SCHEMA §3.4): what each doctor prescribes per diagnosis, incremented at issue.
 *
 * @property int $id
 * @property int $doctor_id
 * @property string|null $icd10_code
 * @property int $generic_id
 * @property int|null $brand_id
 * @property int|null $custom_brand_id
 * @property int|null $strength_id
 * @property CarbonImmutable $period_month
 * @property int $use_count
 * @property array<string, mixed> $last_dose
 * @property CarbonImmutable $last_used_at
 */
final class DoctorDrugUsage extends TenantModel
{
    /** @use HasFactory<DoctorDrugUsageFactory> */
    use HasFactory;

    protected static string $factory = DoctorDrugUsageFactory::class;

    protected $table = 'doctor_drug_usage';

    protected $fillable = ['doctor_id', 'icd10_code', 'generic_id', 'brand_id', 'custom_brand_id', 'strength_id', 'period_month', 'use_count', 'last_dose', 'last_used_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'generic_id' => 'integer', 'brand_id' => 'integer', 'custom_brand_id' => 'integer', 'strength_id' => 'integer',
            'period_month' => 'immutable_date', 'use_count' => 'integer', 'last_dose' => 'array', 'last_used_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Doctor, $this> */
    public function doctor(): BelongsTo
    {
        return $this->belongsTo(Doctor::class);
    }
}
