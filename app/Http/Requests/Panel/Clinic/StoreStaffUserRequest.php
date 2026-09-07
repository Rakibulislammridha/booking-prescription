<?php

declare(strict_types=1);

namespace App\Http\Requests\Panel\Clinic;

use App\Domain\Clinic\Data\StaffUserData;
use App\Domain\Clinic\Enums\Role;
use App\Models\Tenant\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

final class StoreStaffUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('web')?->can('create', User::class) ?? false;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:160'],
            'email' => ['required', 'email', 'max:255', Rule::unique('users', 'email')->whereNull('deleted_at')],
            'mobile' => ['nullable', 'string', 'regex:/^\+8801[3-9]\d{8}$/', Rule::unique('users', 'mobile')->whereNull('deleted_at')],
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
