<?php

declare(strict_types=1);

namespace App\Http\Requests\Super\Catalog;

use App\Domain\Catalog\Actions\UpdateDrugInformation;
use App\Models\Central\SuperAdmin;
use Illuminate\Foundation\Http\FormRequest;

/** `PUT catalog/generics/{id}/information` — the patient-facing text, the slug (until published) and the publish switch. */
final class UpdateDrugInformationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('super') instanceof SuperAdmin;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        $rules = ['public_slug' => ['nullable', 'string', 'max:120', 'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/'], 'published' => ['boolean']];

        foreach (UpdateDrugInformation::FIELDS as $field) {
            $rules[$field] = ['nullable', 'string', 'max:4000'];
        }

        return $rules;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['published' => $this->boolean('published'), 'public_slug' => trim((string) $this->input('public_slug'))]);
    }

    /** @return array<string, string|null> */
    public function text(): array
    {
        $out = [];

        foreach (UpdateDrugInformation::FIELDS as $field) {
            $value = $this->validated($field);
            $out[$field] = is_string($value) ? $value : null;
        }

        return $out;
    }
}
