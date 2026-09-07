<?php

declare(strict_types=1);

namespace App\Http\Requests\Panel\Patients;

use App\Domain\Patients\Data\PatientData;
use App\Models\Tenant\Patient;
use Illuminate\Foundation\Http\FormRequest;

final class UpdatePatientRequest extends FormRequest
{
    public function authorize(): bool
    {
        $patient = $this->route('patient');

        return $patient instanceof Patient && ($this->user('web')?->can('update', $patient) ?? false);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $rules = StorePatientRequest::patientRules();
        unset($rules['primary_public_id'], $rules['relation'], $rules['source']);

        return $rules;
    }

    public function toData(): PatientData
    {
        return PatientData::fromRequest($this);
    }
}
