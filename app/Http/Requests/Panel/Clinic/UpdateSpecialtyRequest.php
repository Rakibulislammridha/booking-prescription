<?php

declare(strict_types=1);

namespace App\Http\Requests\Panel\Clinic;

use App\Domain\Clinic\Data\SpecialtyData;
use App\Models\Tenant\Specialty;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateSpecialtyRequest extends FormRequest
{
    public function authorize(): bool
    {
        $specialty = $this->route('specialty');

        return $specialty instanceof Specialty && ($this->user('web')?->can('update', $specialty) ?? false);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $specialty = $this->route('specialty');
        $id = $specialty instanceof Specialty ? $specialty->id : null;

        return [
            'name' => ['required', 'string', 'max:120'],
            'name_bn' => ['nullable', 'string', 'max:160'],
            'slug' => ['nullable', 'string', 'max:80', 'regex:/^[a-z0-9-]+$/', Rule::unique('specialties', 'slug')->ignore($id)],
            'icon' => ['nullable', 'string', 'max:64'],
            'sort_order' => ['sometimes', 'integer', 'between:0,32767'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function toData(): SpecialtyData
    {
        return SpecialtyData::fromRequest($this);
    }
}
