<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Data;

/**
 * The editable identity of a clinic as the super console sees it (SCHEMA §2.1): names, the primary contact,
 * locale and timezone, the public-site branding, and the platform's own notes. The slug is deliberately absent —
 * it is a hostname, and changing it is its own audited action (`RenameTenantSlug`).
 */
final readonly class TenantProfileData
{
    public function __construct(
        public string $name,
        public ?string $nameBn,
        public string $ownerName,
        public string $ownerEmail,
        public string $ownerMobile,
        public string $locale,
        public string $timezone,
        public ?string $primaryColor,
        public ?string $accentColor,
        public ?string $notes,
        public bool $clearLogo = false,
    ) {}
}
