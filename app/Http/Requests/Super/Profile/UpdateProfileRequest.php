<?php

declare(strict_types=1);

namespace App\Http\Requests\Super\Profile;

use App\Models\Central\SuperAdmin;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** The operator's own name and email. Moving the email — the login identifier — re-asks the current password. */
final class UpdateProfileRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('super') instanceof SuperAdmin;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $admin = $this->admin();
        $emailMoves = (string) $this->input('email') !== $admin->email;

        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique(SuperAdmin::class, 'email')->ignore($admin->id)],
            'current_password' => $emailMoves ? ['required', 'string', 'current_password:super'] : ['nullable', 'string'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'current_password.required' => __('super.profile.error.password_for_email'),
            'current_password.current_password' => __('auth.password_incorrect'),
            'email.unique' => __('super.admins.error.email_taken'),
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['email' => strtolower(trim((string) $this->input('email', '')))]);
    }

    public function admin(): SuperAdmin
    {
        $admin = $this->user('super');

        abort_unless($admin instanceof SuperAdmin, 403);

        return $admin;
    }
}
