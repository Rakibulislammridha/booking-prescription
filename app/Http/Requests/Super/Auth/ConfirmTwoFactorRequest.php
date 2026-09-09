<?php

declare(strict_types=1);

namespace App\Http\Requests\Super\Auth;

use Illuminate\Foundation\Http\FormRequest;

final class ConfirmTwoFactorRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('super') !== null;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return ['code' => ['required', 'string', 'max:16']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['code.required' => __('auth.two_factor.code_required')];
    }

    public function code(): string
    {
        return trim((string) $this->string('code'));
    }
}
