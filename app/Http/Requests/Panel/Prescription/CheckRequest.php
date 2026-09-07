<?php

declare(strict_types=1);

namespace App\Http\Requests\Panel\Prescription;

use App\Models\Tenant\Prescription;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** POST /panel/prescriptions/{prescription}/check — the `items` and `overrides` of §4.13, nothing persisted (§5.5). */
final class CheckRequest extends FormRequest
{
    public function authorize(): bool
    {
        $rx = $this->route('prescription');

        return $rx instanceof Prescription && ($this->user('web')?->can('view', $rx) ?? false);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'items' => ['present', 'array', 'max:40'],
            'items.*.key' => ['required', 'string', 'max:40'],
            'items.*.id' => ['nullable', 'integer'],
            'items.*.drug' => ['nullable', 'array'],
            'items.*.drug.generic_id' => ['nullable', 'integer'],
            'items.*.drug.brand_id' => ['nullable', 'integer'],
            'items.*.drug.strength_id' => ['nullable', 'integer'],
            'items.*.drug.custom_brand_id' => ['nullable', 'integer', Rule::exists('custom_brands', 'id')],
            'items.*.shorthand' => ['present', 'nullable', 'string', 'max:300'],
            'items.*.safety_overrides' => ['sometimes', 'array'],
            'items.*.safety_overrides.*.fingerprint' => ['required', 'string', 'max:200'],
            'items.*.safety_overrides.*.reason' => ['required', 'string', 'max:500'],
            'overrides' => ['sometimes', 'array'],
            'overrides.*.fingerprint' => ['required', 'string', 'max:200'],
            'overrides.*.reason' => ['required', 'string', 'max:500'],
        ];
    }
}
