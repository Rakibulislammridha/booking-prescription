<?php

declare(strict_types=1);

namespace App\Http\Requests\Panel\Clinic;

use App\Domain\Clinic\Data\BranchData;
use App\Models\Tenant\Branch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateBranchRequest extends FormRequest
{
    public function authorize(): bool
    {
        $branch = $this->route('branch');

        return $branch instanceof Branch && ($this->user('web')?->can('update', $branch) ?? false);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $branch = $this->route('branch');
        $id = $branch instanceof Branch ? $branch->id : null;

        return [
            'name' => ['required', 'string', 'max:160'],
            'code' => ['required', 'string', 'max:8', 'alpha_num:ascii', Rule::unique('branches', 'code')->ignore($id)->whereNull('deleted_at')],
            'slug' => ['nullable', 'string', 'max:80', 'regex:/^[a-z0-9-]+$/', Rule::unique('branches', 'slug')->ignore($id)->whereNull('deleted_at')],
            'address' => ['nullable', 'string'],
            'phone' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:255'],
            'is_main' => ['sometimes', 'boolean'],
            'is_active' => ['sometimes', 'boolean'],
            'geo' => ['nullable', 'array:lat,lng'],
            'settings' => ['sometimes', 'array'],
        ];
    }

    public function toData(): BranchData
    {
        return BranchData::fromRequest($this);
    }
}
