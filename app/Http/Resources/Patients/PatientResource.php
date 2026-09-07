<?php

declare(strict_types=1);

namespace App\Http\Resources\Patients;

use App\Models\Tenant\Patient;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The full record for Patients/Show + Edit (staff only, behind PatientPolicy::view). ENC fields are decrypted
 * for the authorised viewer; the view itself is audited by the controller.
 *
 * @mixin Patient
 */
final class PatientResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            ...(new PatientSummaryResource($this->resource))->toArray($request),
            'blood_group' => $this->blood_group?->value,
            'email' => $this->email,
            'address' => $this->address,
            'district' => $this->district,
            'national_id' => $this->national_id,
            'guardian_name' => $this->guardian_name,
            'photo_path' => $this->photo_path,
            'preferred_language' => $this->preferred_language->value,
            'notes' => $this->notes,
            'tags' => $this->tags,
            'registered_branch_id' => $this->registered_branch_id,
            'source' => $this->source->value,
            'created_at' => $this->created_at?->toIso8601ZuluString(),
            'updated_at' => $this->updated_at?->toIso8601ZuluString(),
            'primary' => $this->whenLoaded('primaryRelation', fn () => $this->primaryRelation?->primary === null ? null : [
                ...(new PatientSummaryResource($this->primaryRelation->primary))->toArray($request),
                'relation' => $this->primaryRelation->relation->value,
            ]),
            'dependents' => FamilyMemberResource::collection($this->whenLoaded('dependents')),
            'allergies' => AllergyResource::collection($this->whenLoaded('allergies')),
            'conditions' => ConditionResource::collection($this->whenLoaded('conditions')),
            'medications' => MedicationResource::collection($this->whenLoaded('medications')),
            'documents' => DocumentResource::collection($this->whenLoaded('documents')),
            'consents' => ConsentResource::collection($this->whenLoaded('consents')),
        ];
    }
}
