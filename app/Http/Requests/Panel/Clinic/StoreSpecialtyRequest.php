<?php

declare(strict_types=1);

namespace App\Http\Requests\Panel\Clinic;

use App\Domain\Clinic\Data\SpecialtyData;
use App\Models\Tenant\Specialty;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreSpecialtyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('web')?->can('create', Specialty::class) ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'name_bn' => ['nullable', 'string', 'max:160'],
            'slug' => ['nullable', 'string', 'max:80', 'regex:/^[a-z0-9-]+$/', Rule::unique('specialties', 'slug')],
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
