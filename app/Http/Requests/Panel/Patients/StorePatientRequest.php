<?php

declare(strict_types=1);

namespace App\Http\Requests\Panel\Patients;

use App\Domain\Clinic\Enums\Gender;
use App\Domain\Clinic\Enums\Locale;
use App\Domain\Patients\Data\PatientData;
use App\Domain\Patients\Enums\BloodGroup;
use App\Domain\Patients\Enums\PatientRelation as RelationType;
use App\Domain\Patients\Enums\PatientSource;
use App\Domain\Patients\Services\MobileNumber;
use App\Models\Tenant\Patient;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StorePatientRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('web')?->can('create', Patient::class) ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return self::patientRules();
    }

    /** @return array<string, array<int, mixed>> */
    public static function patientRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
            'mobile' => ['required', 'string', 'max:20', fn (string $attr, mixed $value, \Closure $fail) => MobileNumber::isValid((string) $value) || $fail(__('patients.validation.mobile'))],
            'gender' => ['nullable', Rule::enum(Gender::class)],
            'dob' => ['nullable', 'date', 'before_or_equal:today', 'required_without:age_years'],
            'age_years' => ['nullable', 'integer', 'between:0,130', 'required_without:dob'],
            'blood_group' => ['nullable', Rule::enum(BloodGroup::class)],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:1000'],
            'district' => ['nullable', 'string', 'max:64'],
            'national_id' => ['nullable', 'string', 'max:32'],
            'guardian_name' => ['nullable', 'string', 'max:160'],
            'preferred_language' => ['nullable', Rule::enum(Locale::class)],
            'notes' => ['nullable', 'string', 'max:4000'],
            'tags' => ['sometimes', 'array', 'max:10'],
            'tags.*' => ['string', 'max:32'],
            'registered_branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')],
            'source' => ['nullable', Rule::enum(PatientSource::class)],
            'is_active' => ['sometimes', 'boolean'],
            'primary_public_id' => ['nullable', 'string', 'size:26', Rule::exists('patients', 'public_id')->whereNull('deleted_at')],
            'relation' => ['nullable', Rule::enum(RelationType::class)],
        ];
    }

    public function toData(): PatientData
    {
        return PatientData::fromRequest($this);
    }
}
