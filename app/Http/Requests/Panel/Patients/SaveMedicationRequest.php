<?php

declare(strict_types=1);

namespace App\Http\Requests\Panel\Patients;

use App\Domain\Patients\Data\MedicationData;
use App\Domain\Patients\Enums\MedicationSource;
use App\Models\Tenant\Patient;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SaveMedicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $patient = $this->route('patient');

        return $patient instanceof Patient && ($this->user('web')?->can('manageClinical', $patient) ?? false);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'generic_name' => ['required', 'string', 'max:160'],
            'brand_name' => ['nullable', 'string', 'max:160'],
            'generic_id' => ['nullable', 'integer', 'min:1'],
            'brand_id' => ['nullable', 'integer', 'min:1'],
            'custom_brand_id' => ['nullable', 'integer', 'min:1'],
            'dose_text' => ['nullable', 'string', 'max:120'],
            'source' => ['nullable', Rule::enum(MedicationSource::class)],
            'prescription_item_id' => ['nullable', 'integer', 'min:1'],
            'started_on' => ['nullable', 'date'],
            'ended_on' => ['nullable', 'date', 'after_or_equal:started_on'],
            'is_active' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:4000'],
        ];
    }

    public function toData(): MedicationData
    {
        return MedicationData::fromRequest($this);
    }
}
