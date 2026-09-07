<?php

declare(strict_types=1);

namespace App\Http\Requests\Panel\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

final class ResetPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string'],
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string', 'confirmed', Password::min(8)],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'token.required' => __('validation.auth.token_required'),
            'email.required' => __('validation.auth.email_required'),
            'email.email' => __('validation.auth.email_invalid'),
            'password.required' => __('validation.auth.password_required'),
            'password.confirmed' => __('validation.auth.password_confirmed'),
            'password.min' => __('validation.auth.password_min'),
        ];
    }

    /** @return array{token: string, email: string, password: string, password_confirmation: string} */
    public function credentials(): array
    {
        return [
            'token' => (string) $this->string('token'),
            'email' => strtolower((string) $this->string('email')),
            'password' => (string) $this->string('password'),
            'password_confirmation' => (string) $this->string('password_confirmation'),
        ];
    }
}
