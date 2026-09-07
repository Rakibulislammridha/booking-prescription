<?php

declare(strict_types=1);

namespace App\Http\Resources\Patients;

use App\Models\Tenant\Patient;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Search-result / list row — the same fields the Meilisearch document displays (SCHEMA §5.6): no ENC field,
 * no address. Used by api.patients.search / recent / by_mobile and the panel index.
 *
 * @mixin Patient
 */
final class PatientSummaryResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'patient_code' => $this->patient_code,
            'name' => $this->name,
            'mobile' => $this->mobile,
            'mobile_local' => $this->mobile_local,
            'is_mobile_owner' => $this->is_mobile_owner,
            'gender' => $this->gender?->value,
            'dob' => $this->dob?->toDateString(),
            'dob_is_estimated' => $this->dob_is_estimated,
            'age_text' => $this->age_text,
            'age_years' => $this->age_years,
            'is_active' => $this->is_active,
            'last_visit_at' => $this->last_visit_at?->toIso8601ZuluString(),
            'visit_count' => $this->visit_count,
        ];
    }
}
