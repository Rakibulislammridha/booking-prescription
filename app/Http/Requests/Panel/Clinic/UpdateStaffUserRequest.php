<?php

declare(strict_types=1);

namespace App\Http\Requests\Panel\Clinic;

use App\Domain\Clinic\Data\StaffUserData;
use App\Domain\Clinic\Enums\Role;
use App\Models\Tenant\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

final class UpdateStaffUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        $target = $this->route('user');

        return $target instanceof User && ($this->user('web')?->can('update', $target) ?? false);
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $target = $this->route('user');
        $id = $target instanceof User ? $target->id : null;

        return [
            'name' => ['required', 'string', 'max:160'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->ignore($id)->whereNull('deleted_at')],
            'mobile' => ['nullable', 'string', 'regex:/^\+8801[3-9]\d{8}$/', Rule::unique('users', 'mobile')->ignore($id)->whereNull('deleted_at')],
            'password' => ['nullable', 'string', Password::min(8)],
            'role' => ['required', Rule::enum(Role::class)],
            'default_branch_id' => ['nullable', 'integer', Rule::exists('branches', 'id')->whereNull('deleted_at')],
            'locale' => ['sometimes', Rule::in(['bn', 'en'])],
            'is_active' => ['sometimes', 'boolean'],
            'must_change_password' => ['sometimes', 'boolean'],
            'session_timeout_minutes' => ['nullable', 'integer', 'between:5,1440'],
        ];
    }

    public function toData(): StaffUserData
    {
        return StaffUserData::fromRequest($this);
    }
}
