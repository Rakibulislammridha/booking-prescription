<?php

declare(strict_types=1);

namespace App\Http\Resources\Patients;

use App\Models\Tenant\PatientCondition;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PatientCondition */
final class ConditionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'icd10_code' => $this->icd10_code,
            'condition_name' => $this->condition_name,
            'status' => $this->status->value,
            'onset_date' => $this->onset_date?->toDateString(),
            'resolved_date' => $this->resolved_date?->toDateString(),
            'notes' => $this->notes,
            'recorded_by_user_id' => $this->recorded_by_user_id,
            'created_at' => $this->created_at->toIso8601ZuluString(),
            'updated_at' => $this->updated_at->toIso8601ZuluString(),
        ];
    }
}
