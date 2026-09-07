<?php

declare(strict_types=1);

namespace App\Http\Requests\Panel\Patients;

use App\Domain\Patients\Data\AllergyData;
use App\Domain\Patients\Enums\AllergenType;
use App\Domain\Patients\Enums\AllergySeverity;
use App\Models\Tenant\Patient;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create + update (PRESCRIPTION.md §8). Catalog soft references (generic_id, allergy_class_id) are validated with
 * `Rule::catalog()` once the Catalog module ships CatalogIdExists; until then they are integers.
 */
final class SaveAllergyRequest extends FormRequest
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
            'allergen_type' => ['required', Rule::enum(AllergenType::class)],
            'allergen_name' => ['required', 'string', 'max:160'],
            'generic_id' => ['nullable', 'integer', 'min:1', Rule::requiredIf(fn () => $this->input('allergen_type') === AllergenType::Generic->value)],
            'allergy_class_id' => ['nullable', 'integer', 'min:1', Rule::requiredIf(fn () => $this->input('allergen_type') === AllergenType::AllergyClass->value)],
            'reaction' => ['nullable', 'string', 'max:255'],
            'severity' => ['nullable', Rule::enum(AllergySeverity::class)],
            'notes' => ['nullable', 'string', 'max:4000'],
            'is_active' => ['sometimes', 'boolean'],
            'verified_by_doctor_id' => ['nullable', 'integer', Rule::exists('doctors', 'id')->whereNull('deleted_at')],
        ];
    }

    public function toData(): AllergyData
    {
        return AllergyData::fromRequest($this);
    }
}
