<?php

declare(strict_types=1);

namespace App\Http\Requests\Super\Auth;

use Illuminate\Foundation\Http\FormRequest;

final class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        // No `remember`: the super guard has no remember-me (B4).
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'email.required' => __('validation.auth.email_required'),
            'email.email' => __('validation.auth.email_invalid'),
            'password.required' => __('validation.auth.password_required'),
        ];
    }

    /** @return array{email: string, password: string} */
    public function credentials(): array
    {
        return ['email' => strtolower((string) $this->string('email')), 'password' => (string) $this->string('password')];
    }
}
