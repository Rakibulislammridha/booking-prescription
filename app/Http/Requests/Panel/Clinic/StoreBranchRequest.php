<?php

declare(strict_types=1);

namespace App\Http\Requests\Panel\Clinic;

use App\Domain\Clinic\Data\BranchData;
use App\Models\Tenant\Branch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreBranchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('web')?->can('create', Branch::class) ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
            'code' => ['required', 'string', 'max:8', 'alpha_num:ascii', Rule::unique('branches', 'code')->whereNull('deleted_at')],
            'slug' => ['nullable', 'string', 'max:80', 'regex:/^[a-z0-9-]+$/', Rule::unique('branches', 'slug')->whereNull('deleted_at')],
            'address' => ['nullable', 'string'],
            'phone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:255'],
            'is_main' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'geo' => ['nullable', 'array:lat,lng'],
            'geo.lat' => ['required_with:geo', 'numeric', 'between:-90,90'],
            'geo.lng' => ['required_with:geo', 'numeric', 'between:-180,180'],
            'settings' => ['sometimes', 'array'],
            'settings.token_slip_width_mm' => ['sometimes', 'integer', Rule::in([58, 80, 148])],
            'settings.display_mode' => ['sometimes', 'array'],
        ];
    }

    public function toData(): BranchData
    {
        return BranchData::fromRequest($this);
    }
}
