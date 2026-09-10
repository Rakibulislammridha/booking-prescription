<?php

declare(strict_types=1);

namespace App\Http\Requests\Super\Tenants;

use App\Domain\Clinic\Enums\Role;
use App\Domain\SaaS\Data\CredentialReveal;
use App\Domain\SaaS\Data\TenantStaffData;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A staff account created for a clinic from the console. E-mail uniqueness is NOT a rule here: `users` is a
 * tenant table and this request runs on `public`; `CreateTenantStaffUser` checks it inside the clinic and the
 * controller maps the refusal back onto the `email` field.
 */
final class StoreTenantStaffRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('super') !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:160'],
            'email' => ['required', 'email:rfc', 'max:255'],
            'mobile' => ['nullable', 'string', 'regex:/^(\+?880|0)1[3-9]\d{8}$/'],
            'role' => ['required', Rule::enum(Role::class)],
            'locale' => ['sometimes', Rule::in(['bn', 'en'])],
            'credential' => ['required', Rule::in([CredentialReveal::KIND_PASSWORD, CredentialReveal::KIND_LINK])],
            'password' => ['nullable', 'required_if:credential,'.CredentialReveal::KIND_PASSWORD, 'string', 'min:8', 'max:72'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'mobile.regex' => __('saas.onboarding.mobile_invalid'),
            'password.required_if' => __('super.tenants.form.password_required'),
        ];
    }

    public function toData(): TenantStaffData
    {
        /** @var array<string, mixed> $v */
        $v = $this->validated();
        $mobile = is_string($v['mobile'] ?? null) && trim($v['mobile']) !== '' ? trim($v['mobile']) : null;

        return new TenantStaffData(
            name: (string) $v['name'],
            email: mb_strtolower((string) $v['email']),
            role: Role::from((string) $v['role']),
            password: $v['credential'] === CredentialReveal::KIND_PASSWORD ? (string) $v['password'] : null,
            mobile: $mobile === null ? null : '+'.(str_starts_with(ltrim($mobile, '+'), '880') ? ltrim($mobile, '+') : '88'.ltrim($mobile, '+')),
            locale: (string) ($v['locale'] ?? 'bn'),
        );
    }
}
