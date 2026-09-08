<?php

declare(strict_types=1);

namespace App\Http\Requests\Central;

use App\Domain\SaaS\Data\OnboardingData;
use App\Domain\Tenancy\Rules\NotReservedSlug;
use App\Models\Central\Plan;
use App\Models\Central\Tenant;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The wizard's one and only write. Everything a clinic can get wrong is caught here, before `ProvisionTenant`
 * creates a Postgres schema — provisioning is expensive and only partially reversible, so it must never be the
 * thing that discovers a duplicate slug.
 *
 * The slug is validated against the SAME rule the tenancy layer uses (`NotReservedSlug`), including soft-deleted
 * tenants: a slug is a hostname, and reusing one would point the internet at a resurrected clinic.
 */
final class SignUpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'clinic_name' => ['required', 'string', 'min:2', 'max:160'],
            'slug' => ['required', 'string', 'min:2', 'max:63', 'regex:/^[a-z0-9][a-z0-9-]{1,62}$/', new NotReservedSlug, Rule::unique(Tenant::class, 'slug')->withoutTrashed()],
            'owner_name' => ['required', 'string', 'min:2', 'max:160'],
            'owner_email' => ['required', 'email:rfc', 'max:255'],
            'owner_mobile' => ['required', 'string', 'regex:/^\+?8801[3-9]\d{8}$/'],
            'password' => ['required', 'string', 'min:8', 'max:72', 'confirmed'],
            'plan' => ['required', 'string', Rule::exists(Plan::class, 'code')->where(fn ($q) => $q->whereNull('archived_at')->where('is_public', true)->where('is_addon', false))],
            'branch_name' => ['nullable', 'string', 'max:160'],
            'locale' => ['required', Rule::in(['bn', 'en'])],
            'timezone' => ['required', 'string', 'max:64', 'timezone'],
            'demo' => ['boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'slug.regex' => __('tenancy.slug_invalid'),
            'slug.unique' => __('saas.onboarding.slug_taken'),
            'owner_mobile.regex' => __('saas.onboarding.mobile_invalid'),
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'slug' => mb_strtolower(trim((string) $this->input('slug'))),
            'timezone' => (string) ($this->input('timezone') ?: 'Asia/Dhaka'),
            'demo' => $this->boolean('demo'),
        ]);
    }

    public function toData(): OnboardingData
    {
        /** @var array<string, mixed> $data */
        $data = $this->validated();

        return new OnboardingData(
            clinicName: (string) $data['clinic_name'],
            slug: (string) $data['slug'],
            ownerName: (string) $data['owner_name'],
            ownerEmail: (string) $data['owner_email'],
            ownerMobile: $this->e164((string) $data['owner_mobile']),
            adminPassword: (string) $data['password'],
            planCode: (string) $data['plan'],
            demo: (bool) ($data['demo'] ?? false),
            locale: (string) $data['locale'],
            timezone: (string) $data['timezone'],
            branchName: isset($data['branch_name']) ? (string) $data['branch_name'] : null,
        );
    }

    private function e164(string $mobile): string
    {
        return str_starts_with($mobile, '+') ? $mobile : '+'.ltrim($mobile, '0');
    }
}
