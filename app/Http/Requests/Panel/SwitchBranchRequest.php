<?php

declare(strict_types=1);

namespace App\Http\Requests\Panel;

use App\Models\Tenant\Branch;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** PATCH /panel/branch — {branch_id}. Any staff user may switch between the tenant's active branches. */
final class SwitchBranchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('web') !== null;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'branch_id' => ['required', 'integer', Rule::exists('branches', 'id')->where('is_active', true)->whereNull('deleted_at')],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'branch_id.required' => __('validation.branch.required'),
            'branch_id.integer' => __('validation.branch.invalid'),
            'branch_id.exists' => __('validation.branch.invalid'),
        ];
    }

    public function branch(): Branch
    {
        return Branch::query()->active()->findOrFail($this->integer('branch_id'));
    }
}
