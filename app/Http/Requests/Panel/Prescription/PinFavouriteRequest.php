<?php

declare(strict_types=1);

namespace App\Http\Requests\Panel\Prescription;

use App\Domain\Clinic\Enums\Permission;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** POST /panel/doctors/me/favourites {icd10_code?, drug{…}, default_dose?} ("Pin for J06.9", §3.7). */
final class PinFavouriteRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user('web');

        return $user !== null && $user->can(Permission::PrescriptionsWrite->value) && $user->doctor !== null;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'icd10_code' => ['nullable', 'string', 'max:8', Rule::catalog('icd10_codes')],
            'drug' => ['required', 'array'],
            'drug.generic_id' => ['nullable', 'integer', Rule::catalog('generics', false)],
            'drug.brand_id' => ['nullable', 'integer', Rule::catalog('brands', false)],
            'drug.strength_id' => ['nullable', 'integer', Rule::catalog('strengths', false)],
            'drug.custom_brand_id' => ['nullable', 'integer', Rule::exists('custom_brands', 'id')],
            'default_dose' => ['nullable', 'array'],
            'default_dose.shorthand' => ['nullable', 'string', 'max:300'],
        ];
    }
}
