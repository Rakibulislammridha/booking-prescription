<?php

declare(strict_types=1);

namespace App\Http\Requests\Super\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Reissuing recovery codes silently invalidates the operator's old ones and hands out a fresh, durable set — so it
 * is a credential-minting action and, like disabling the factor, it re-asks for the password AND a current
 * authenticator code (B4). Without this a session that reached the management screen without proving possession
 * (the old exempt-route hole) could print itself a new way in. The code is verified (and spent) in the controller.
 */
final class RegenerateRecoveryCodesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('super') !== null;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'password' => ['required', 'string', 'current_password:super'],
            'code' => ['required', 'string', 'max:16'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'password.required' => __('validation.auth.password_required'),
            'password.current_password' => __('auth.password_incorrect'),
            'code.required' => __('auth.two_factor.code_required'),
        ];
    }

    public function code(): string
    {
        return trim((string) $this->string('code'));
    }
}
