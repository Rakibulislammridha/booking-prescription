<?php

declare(strict_types=1);

namespace App\Http\Requests\Super\Admins;

use App\Domain\SaaS\Data\SuperAdminData;
use App\Domain\SaaS\Support\SuperPassword;
use App\Models\Central\SuperAdmin;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A new operator. Either a password (same strength as `super:create`) or none — then a set-password link is
 * mailed. Creating a console account is itself a credential-grade action, so the creating operator re-types
 * their own password whichever way the new one is set.
 */
final class StoreSuperAdminRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('super') instanceof SuperAdmin;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique(SuperAdmin::class, 'email')],
            'password' => ['nullable', 'string', 'confirmed', SuperPassword::rule()],
            'send_link' => ['nullable', 'boolean'],
            'current_password' => ['required', 'string', 'current_password:super'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'current_password.required' => __('validation.auth.password_required'),
            'current_password.current_password' => __('auth.password_incorrect'),
            'email.unique' => __('super.admins.error.email_taken'),
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['email' => strtolower(trim((string) $this->input('email', '')))]);
    }

    public function toData(): SuperAdminData
    {
        $password = (string) $this->input('password', '');

        return new SuperAdminData(
            name: trim((string) $this->string('name')),
            email: (string) $this->string('email'),
            password: $this->boolean('send_link') || $password === '' ? null : $password,
        );
    }

    public function admin(): SuperAdmin
    {
        $admin = $this->user('super');

        abort_unless($admin instanceof SuperAdmin, 403);

        return $admin;
    }
}
