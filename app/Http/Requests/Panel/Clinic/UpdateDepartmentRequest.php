<?php

declare(strict_types=1);

namespace App\Http\Requests\Panel\Clinic;

use App\Domain\Clinic\Data\DepartmentData;
use App\Models\Tenant\Department;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateDepartmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        $department = $this->route('department');

        return $department instanceof Department && ($this->user('web')?->can('update', $department) ?? false);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $department = $this->route('department');
        $id = $department instanceof Department ? $department->id : null;

        return [
            'name' => ['required', 'string', 'max:120'],
            'name_bn' => ['nullable', 'string', 'max:160'],
            'slug' => ['nullable', 'string', 'max:80', 'regex:/^[a-z0-9-]+$/', Rule::unique('departments', 'slug')->ignore($id)],
            'branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')->whereNull('deleted_at')],
            'sort_order' => ['sometimes', 'integer', 'between:0,32767'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function toData(): DepartmentData
    {
        return DepartmentData::fromRequest($this);
    }
}
