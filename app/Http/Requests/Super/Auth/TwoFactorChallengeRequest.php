<?php

declare(strict_types=1);

namespace App\Http\Requests\Super\Auth;

use Illuminate\Foundation\Http\FormRequest;

/**
 * One of the two, never both: a six-digit TOTP code or a recovery code. The rules keep the shapes apart so a
 * recovery code typed into the code box is a validation error rather than a burned attempt.
 */
final class TwoFactorChallengeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'code' => ['required_without:recovery_code', 'nullable', 'string', 'max:16'],
            'recovery_code' => ['required_without:code', 'nullable', 'string', 'max:64'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'code.required_without' => __('auth.two_factor.code_required'),
            'recovery_code.required_without' => __('auth.two_factor.code_required'),
        ];
    }

    public function code(): string
    {
        return trim((string) $this->string('code'));
    }

    public function recoveryCode(): string
    {
        return trim((string) $this->string('recovery_code'));
    }

    public function usesRecoveryCode(): bool
    {
        return $this->recoveryCode() !== '';
    }
}
