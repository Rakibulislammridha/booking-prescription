<?php

declare(strict_types=1);

namespace App\Http\Requests\Super\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Turning the second factor off is a downgrade of the platform's most valuable credential, so it re-asks for the
 * password: an unattended console must not be one click away from an unprotected super account.
 */
final class DisableTwoFactorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('super') !== null;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return ['password' => ['required', 'string', 'current_password:super']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'password.required' => __('validation.auth.password_required'),
            'password.current_password' => __('auth.password_incorrect'),
        ];
    }
}
