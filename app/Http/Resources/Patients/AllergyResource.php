<?php

declare(strict_types=1);

namespace App\Http\Resources\Patients;

use App\Models\Tenant\PatientAllergy;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PatientAllergy */
final class AllergyResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'allergen_type' => $this->allergen_type->value,
            'generic_id' => $this->generic_id,
            'allergy_class_id' => $this->allergy_class_id,
            'allergen_name' => $this->allergen_name,
            'reaction' => $this->reaction,
            'severity' => $this->severity->value,
            'notes' => $this->notes,
            'is_active' => $this->is_active,
            'recorded_by_user_id' => $this->recorded_by_user_id,
            'verified_by_doctor_id' => $this->verified_by_doctor_id,
            'created_at' => $this->created_at->toIso8601ZuluString(),
            'updated_at' => $this->updated_at->toIso8601ZuluString(),
        ];
    }
}
