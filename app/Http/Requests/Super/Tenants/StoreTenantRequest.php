<?php

declare(strict_types=1);

namespace App\Http\Requests\Super\Tenants;

use App\Domain\SaaS\Data\ConsoleTenantData;
use App\Domain\SaaS\Data\CredentialReveal;
use App\Domain\SaaS\Services\DomainName;
use App\Domain\Tenancy\Rules\NotReservedSlug;
use App\Models\Central\Plan;
use App\Models\Central\Tenant;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Onboarding a clinic from the console. The slug rules are the wizard's (`Central\SignUpRequest`) — the same
 * `NotReservedSlug`, the same uniqueness INCLUDING soft-deleted rows, because a slug is a hostname and a
 * resurrected address is a phishing page waiting to happen. What differs is who is trusted: the operator may pick
 * any live base plan (public or not), set the trial by hand, add a custom domain up front, and choose whether to
 * type the owner's password or hand them a set-password link.
 */
final class StoreTenantRequest extends FormRequest
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
            'name_bn' => ['nullable', 'string', 'max:200'],
            'slug' => ['required', 'string', 'min:2', 'max:63', 'regex:/^[a-z0-9][a-z0-9-]{1,62}$/', new NotReservedSlug, Rule::unique(Tenant::class, 'slug')],
            'plan' => ['required', 'string', Rule::exists(Plan::class, 'code')->where(fn ($q) => $q->whereNull('archived_at')->where('is_addon', false))],
            'trial_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'locale' => ['required', Rule::in(['bn', 'en'])],
            'timezone' => ['required', 'string', 'max:64', 'timezone:all'],
            'owner_name' => ['required', 'string', 'min:2', 'max:160'],
            'owner_email' => ['required', 'email:rfc', 'max:255'],
            'owner_mobile' => ['required', 'string', 'regex:/^(\+?880|0)1[3-9]\d{8}$/'],
            'admin_name' => ['nullable', 'string', 'max:160'],
            'admin_email' => ['nullable', 'email:rfc', 'max:255'],
            'credential' => ['required', Rule::in([CredentialReveal::KIND_PASSWORD, CredentialReveal::KIND_LINK])],
            'password' => ['nullable', 'required_if:credential,'.CredentialReveal::KIND_PASSWORD, 'string', 'min:8', 'max:72'],
            'demo' => ['boolean'],
            'custom_domain' => ['nullable', 'string', 'max:253', $this->customDomainRule()],
            'branch_name' => ['nullable', 'string', 'max:160'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'slug.regex' => __('tenancy.slug_invalid'),
            'slug.unique' => __('saas.onboarding.slug_taken'),
            'owner_mobile.regex' => __('saas.onboarding.mobile_invalid'),
            'password.required_if' => __('super.tenants.form.password_required'),
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'slug' => mb_strtolower(trim((string) $this->input('slug'))),
            'timezone' => (string) ($this->input('timezone') ?: 'Asia/Dhaka'),
            'demo' => $this->boolean('demo'),
            'custom_domain' => $this->filled('custom_domain') ? DomainName::normalise((string) $this->input('custom_domain')) : null,
            'trial_days' => $this->filled('trial_days') ? $this->input('trial_days') : null,
        ]);
    }

    public function toData(): ConsoleTenantData
    {
        /** @var array<string, mixed> $v */
        $v = $this->validated();
        $ownerEmail = mb_strtolower((string) $v['owner_email']);

        return new ConsoleTenantData(
            name: (string) $v['name'],
            nameBn: $this->stringOrNull($v['name_bn'] ?? null),
            slug: (string) $v['slug'],
            planCode: (string) $v['plan'],
            trialDays: isset($v['trial_days']) ? (int) $v['trial_days'] : null,
            locale: (string) $v['locale'],
            timezone: (string) $v['timezone'],
            ownerName: (string) $v['owner_name'],
            ownerEmail: $ownerEmail,
            ownerMobile: $this->e164((string) $v['owner_mobile']),
            adminName: $this->stringOrNull($v['admin_name'] ?? null) ?? (string) $v['owner_name'],
            adminEmail: mb_strtolower($this->stringOrNull($v['admin_email'] ?? null) ?? $ownerEmail),
            adminPassword: $v['credential'] === CredentialReveal::KIND_PASSWORD ? (string) $v['password'] : null,
            demo: (bool) ($v['demo'] ?? false),
            customDomain: $this->stringOrNull($v['custom_domain'] ?? null),
            branchName: $this->stringOrNull($v['branch_name'] ?? null),
            notes: $this->stringOrNull($v['notes'] ?? null),
        );
    }

    /** A usable public hostname that is not one of the platform's own — the same refusal `AddCustomDomain` makes. */
    private function customDomainRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $host = is_string($value) ? DomainName::normalise($value) : '';

            if (! DomainName::isValid($host) || DomainName::isPlatformHost($host, (string) config('tenancy.central_domain'))) {
                $fail((string) __('saas.domains.already_claimed', ['domain' => $host]));
            }
        };
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /** `01712345678`, `8801712345678` and `+8801712345678` are the same phone; store the E.164 form (SCHEMA §2.1). */
    private function e164(string $mobile): string
    {
        $digits = ltrim($mobile, '+');

        return '+'.(str_starts_with($digits, '880') ? $digits : '88'.$digits);
    }
}
