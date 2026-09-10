<?php

declare(strict_types=1);

namespace App\Http\Requests\Super\Profile;

use App\Domain\SaaS\Support\SuperPassword;
use App\Models\Central\SuperAdmin;
use Illuminate\Foundation\Http\FormRequest;

/** Current password, then a new one that meets the same rule as `super:create`, confirmed. */
final class ChangePasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('super') instanceof SuperAdmin;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'current_password' => ['required', 'string', 'current_password:super'],
            'password' => ['required', 'string', 'confirmed', 'different:current_password', SuperPassword::rule()],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'current_password.required' => __('validation.auth.password_required'),
            'current_password.current_password' => __('auth.password_incorrect'),
            'password.confirmed' => __('validation.auth.password_confirmed'),
            'password.different' => __('super.profile.error.password_same'),
        ];
    }

    public function password(): string
    {
        return (string) $this->string('password');
    }

    public function admin(): SuperAdmin
    {
        $admin = $this->user('super');

        abort_unless($admin instanceof SuperAdmin, 403);

        return $admin;
    }
}
