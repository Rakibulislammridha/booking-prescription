<?php

declare(strict_types=1);

namespace App\Http\Resources\Prescription;

use App\Models\Tenant\Prescription;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of the prescriptions index: identity, status and version, who and when, the patient and doctor labels and
 * the first diagnosis — never the snapshot. The list is a finder; Show renders the document.
 *
 * @mixin Prescription
 */
final class PrescriptionRowResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $title = $this->visit->diagnoses[0]['title'] ?? null;

        return [
            'id' => $this->public_id,
            'version' => $this->version,
            'status' => $this->status->value,
            'language' => $this->language->value,
            'issued_at' => $this->issued_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'verification_code' => $this->verification_code,
            'pdf_status' => $this->pdf_path !== null ? 'ready' : 'pending',
            'items_count' => (int) ($this->getAttribute('items_count') ?? 0),
            'diagnosis' => is_string($title) ? $title : null,
            'visit_id' => $this->visit->public_id,
            // Loaded withTrashed by PrescriptionIndexQuery: a merged-away patient or a retired doctor is still named.
            'patient' => [
                'public_id' => $this->patient->public_id,
                'patient_code' => $this->patient->patient_code,
                'name' => $this->patient->name,
                'mobile_local' => $this->patient->mobile_local,
                'age_text' => $this->patient->age_text,
                'gender' => $this->patient->gender?->value,
            ],
            'doctor' => [
                'public_id' => $this->doctor->public_id,
                'name' => $this->doctor->name,
                'name_bn' => $this->doctor->name_bn,
            ],
        ];
    }
}
