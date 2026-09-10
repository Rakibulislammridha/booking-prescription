<?php

declare(strict_types=1);

namespace App\Http\Requests\Super\Admins;

use App\Domain\SaaS\Data\SuperAdminData;
use App\Domain\SaaS\Support\SuperPassword;
use App\Models\Central\SuperAdmin;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Another operator's name and email; a new password too, if typed — that half re-asks the editor's own password. */
final class UpdateSuperAdminRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('super') instanceof SuperAdmin;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $target = $this->route('admin');
        $password = (string) $this->input('password', '');

        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique(SuperAdmin::class, 'email')->ignore($target instanceof SuperAdmin ? $target->id : null)],
            'password' => ['nullable', 'string', 'confirmed', SuperPassword::rule()],
            'current_password' => $password === '' ? ['nullable', 'string'] : ['required', 'string', 'current_password:super'],
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
            password: $password === '' ? null : $password,
        );
    }

    public function admin(): SuperAdmin
    {
        $admin = $this->user('super');

        abort_unless($admin instanceof SuperAdmin, 403);

        return $admin;
    }
}
