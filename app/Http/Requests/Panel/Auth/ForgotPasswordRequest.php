<?php

declare(strict_types=1);

namespace App\Http\Requests\Panel\Auth;

use Illuminate\Foundation\Http\FormRequest;

final class ForgotPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return ['email' => ['required', 'string', 'email']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'email.required' => __('validation.auth.email_required'),
            'email.email' => __('validation.auth.email_invalid'),
        ];
    }

    public function email(): string
    {
        return strtolower((string) $this->string('email'));
    }
}
