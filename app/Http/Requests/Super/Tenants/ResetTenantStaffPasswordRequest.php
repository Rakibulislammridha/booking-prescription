<?php

declare(strict_types=1);

namespace App\Http\Requests\Super\Tenants;

use App\Domain\SaaS\Data\CredentialReveal;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Which kind of hand-off support wants: a temporary password shown once, or a set-password link. */
final class ResetTenantStaffPasswordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('super') !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return ['mode' => ['required', Rule::in([CredentialReveal::KIND_PASSWORD, CredentialReveal::KIND_LINK])]];
    }

    public function mode(): string
    {
        return (string) $this->validated('mode');
    }
}
