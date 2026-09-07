<?php

declare(strict_types=1);

namespace App\Http\Resources\Patients;

use App\Models\Tenant\PatientMedication;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PatientMedication */
final class MedicationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'generic_id' => $this->generic_id,
            'brand_id' => $this->brand_id,
            'custom_brand_id' => $this->custom_brand_id,
            'generic_name' => $this->generic_name,
            'brand_name' => $this->brand_name,
            'dose_text' => $this->dose_text,
            'source' => $this->source->value,
            'prescription_item_id' => $this->prescription_item_id,
            'started_on' => $this->started_on?->toDateString(),
            'ended_on' => $this->ended_on?->toDateString(),
            'is_active' => $this->is_active,
            'notes' => $this->notes,
            'created_at' => $this->created_at->toIso8601ZuluString(),
            'updated_at' => $this->updated_at->toIso8601ZuluString(),
        ];
    }
}
