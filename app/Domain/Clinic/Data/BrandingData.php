<?php

declare(strict_types=1);

namespace App\Domain\Clinic\Data;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The public-facing identity of the clinic: `public.tenants.name`, `tenants.locale` and the `branding` jsonb
 * (SCHEMA §2.1) that HandleInertiaRequests turns into `tenant.logo_url` and the `--tenant-*` CSS variables.
 */
final readonly class BrandingData
{
    public function __construct(
        public string $name,
        public string $locale,
        public ?string $nameBn = null,
        public ?string $primaryColor = null,
        public ?string $accentColor = null,
        public ?string $onPrimaryColor = null,
        public ?string $logoPath = null,
        public bool $clearLogo = false,
    ) {}

    public static function fromRequest(FormRequest $request): self
    {
        $v = $request->validated();

        return new self(
            name: (string) $v['name'],
            locale: (string) ($v['locale'] ?? 'bn'),
            nameBn: $v['name_bn'] ?? null,
            primaryColor: $v['primary_color'] ?? null,
            accentColor: $v['accent_color'] ?? null,
            onPrimaryColor: $v['on_primary_color'] ?? null,
            logoPath: null,
            clearLogo: (bool) ($v['clear_logo'] ?? false),
        );
    }

    public function withLogoPath(?string $path): self
    {
        return $path === null ? $this : new self(
            $this->name, $this->locale, $this->nameBn, $this->primaryColor, $this->accentColor, $this->onPrimaryColor, $path, false,
        );
    }

    /**
     * Merged over whatever the tenant already stores, so keys other modules own (favicon_path, tagline_*) survive.
     *
     * @param  array<string, mixed>  $existing
     * @return array<string, mixed>
     */
    public function toBranding(array $existing): array
    {
        $logo = $this->clearLogo ? null : ($this->logoPath ?? ($existing['logo_path'] ?? null));

        return array_replace($existing, [
            'name_bn' => $this->nameBn,
            'primary_color' => $this->primaryColor,
            'accent_color' => $this->accentColor,
            'on_primary_color' => $this->onPrimaryColor,
            'logo_path' => $logo,
        ]);
    }
}
