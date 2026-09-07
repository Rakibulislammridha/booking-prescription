<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Actions;

use App\Domain\Prescription\Services\DoctorLearningCache;
use App\Domain\Prescription\Services\DrugRefResolver;
use App\Domain\Shared\Actor;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\DoctorFavourite;

/** "Pin for J06.9" on a search hit (PRESCRIPTION.md §3.7): upsert the (dx, drug) row as pinned. */
final class PinFavourite
{
    public function __construct(private readonly DrugRefResolver $drugs, private readonly DoctorLearningCache $cache) {}

    /** @param  array<string, mixed>  $data  {icd10_code?, drug{generic_id, brand_id?, custom_brand_id?, strength_id?}, default_dose?} */
    public function handle(Doctor $doctor, array $data, Actor $actor): DoctorFavourite
    {
        $drug = $this->drugs->resolve((array) $data['drug']);
        $icd = isset($data['icd10_code']) && $data['icd10_code'] !== '' ? strtoupper((string) $data['icd10_code']) : null;

        $row = DoctorFavourite::query()->where('doctor_id', $doctor->id)->where('generic_id', $drug->genericId)
            ->where(fn ($q) => $icd === null ? $q->whereNull('icd10_code') : $q->where('icd10_code', $icd))
            ->where(fn ($q) => $drug->brandId === null ? $q->whereNull('brand_id') : $q->where('brand_id', $drug->brandId))
            ->where(fn ($q) => $drug->customBrandId === null ? $q->whereNull('custom_brand_id') : $q->where('custom_brand_id', $drug->customBrandId))
            ->first() ?? new DoctorFavourite(['doctor_id' => $doctor->id, 'icd10_code' => $icd, 'generic_id' => $drug->genericId, 'brand_id' => $drug->brandId, 'custom_brand_id' => $drug->customBrandId, 'use_count' => 0, 'rank' => 0]);

        $row->fill(['strength_id' => $drug->strengthId, 'label' => $drug->label(), 'is_pinned' => true, 'default_dose' => (array) ($data['default_dose'] ?? $row->default_dose ?? [])]);
        $row->save();
        $this->cache->refresh($doctor->id, $icd !== null ? [$icd] : []);

        return $row;
    }
}
