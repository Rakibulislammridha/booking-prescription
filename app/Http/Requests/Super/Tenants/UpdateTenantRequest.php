<?php

declare(strict_types=1);

namespace App\Http\Requests\Super\Tenants;

use App\Domain\SaaS\Data\TenantProfileData;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The console's edit of a clinic (SCHEMA §2.1). No slug here on purpose — the address is a hostname and changes
 * through `super.tenants.slug`, with its own confirmation. Colours must be hex: HandleInertiaRequests interpolates
 * them into `--tenant-*` CSS variables and silently drops anything else.
 */
final class UpdateTenantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('super') !== null;
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        $hex = ['nullable', 'string', 'regex:/^#[0-9a-fA-F]{3,8}$/'];

        return [
            'name' => ['required', 'string', 'min:2', 'max:160'],
            'name_bn' => ['nullable', 'string', 'max:200'],
            'owner_name' => ['required', 'string', 'min:2', 'max:160'],
            'owner_email' => ['required', 'email:rfc', 'max:255'],
            'owner_mobile' => ['required', 'string', 'regex:/^(\+?880|0)1[3-9]\d{8}$/'],
            'locale' => ['required', Rule::in(['bn', 'en'])],
            'timezone' => ['required', 'string', 'max:64', 'timezone:all'],
            'primary_color' => $hex,
            'accent_color' => $hex,
            'logo' => ['nullable', 'image', 'mimes:png,jpg,jpeg,webp,svg', 'max:2048'],
            'clear_logo' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['owner_mobile.regex' => __('saas.onboarding.mobile_invalid')];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['clear_logo' => $this->boolean('clear_logo')]);
    }

    public function toData(): TenantProfileData
    {
        /** @var array<string, mixed> $v */
        $v = $this->validated();
        $digits = ltrim((string) $v['owner_mobile'], '+');

        return new TenantProfileData(
            name: (string) $v['name'],
            nameBn: $this->stringOrNull($v['name_bn'] ?? null),
            ownerName: (string) $v['owner_name'],
            ownerEmail: mb_strtolower((string) $v['owner_email']),
            ownerMobile: '+'.(str_starts_with($digits, '880') ? $digits : '88'.$digits),
            locale: (string) $v['locale'],
            timezone: (string) $v['timezone'],
            primaryColor: $this->stringOrNull($v['primary_color'] ?? null),
            accentColor: $this->stringOrNull($v['accent_color'] ?? null),
            notes: $this->stringOrNull($v['notes'] ?? null),
            clearLogo: (bool) ($v['clear_logo'] ?? false),
        );
    }

    private function stringOrNull(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
