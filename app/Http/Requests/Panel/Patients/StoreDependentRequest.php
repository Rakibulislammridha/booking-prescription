<?php

declare(strict_types=1);

namespace App\Http\Requests\Panel\Patients;

use App\Domain\Patients\Data\PatientData;
use App\Domain\Patients\Enums\PatientRelation as RelationType;
use App\Models\Tenant\Patient;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** "Add family member" on Patients/Show: same mobile as the primary, so `mobile` is not accepted here. */
final class StoreDependentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('web')?->can('create', Patient::class) ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $rules = StorePatientRequest::patientRules();
        unset($rules['mobile'], $rules['primary_public_id']);
        $rules['relation'] = ['required', Rule::enum(RelationType::class)];

        return $rules;
    }

    public function toData(Patient $primary): PatientData
    {
        return PatientData::fromArray($this->validated() + ['mobile' => $primary->mobile], $this->user('web')?->getKey());
    }
}
