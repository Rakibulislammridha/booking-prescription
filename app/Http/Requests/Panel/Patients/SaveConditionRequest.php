<?php

declare(strict_types=1);

namespace App\Http\Requests\Panel\Patients;

use App\Domain\Patients\Data\ConditionData;
use App\Domain\Patients\Enums\ConditionStatus;
use App\Models\Tenant\Patient;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SaveConditionRequest extends FormRequest
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
            'condition_name' => ['required', 'string', 'max:200'],
            'icd10_code' => ['nullable', 'string', 'max:8', 'regex:/^[A-Za-z]\d{2}(\.\d{1,4})?$/'],
            'status' => ['nullable', Rule::enum(ConditionStatus::class)],
            'onset_date' => ['nullable', 'date', 'before_or_equal:today'],
            'resolved_date' => ['nullable', 'date', 'after_or_equal:onset_date'],
            'notes' => ['nullable', 'string', 'max:4000'],
        ];
    }

    public function toData(): ConditionData
    {
        return ConditionData::fromRequest($this);
    }
}
