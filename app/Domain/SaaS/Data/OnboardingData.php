<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Data;

use App\Domain\Tenancy\Data\ProvisionTenantData;

/** What the four-step sign-up wizard collects before `ProvisionTenant` is allowed to touch anything. */
final readonly class OnboardingData
{
    public function __construct(
        public string $clinicName,
        public string $slug,
        public string $ownerName,
        public string $ownerEmail,
        public string $ownerMobile,
        public string $adminPassword,
        public string $planCode,
        public bool $demo = false,
        public string $locale = 'bn',
        public string $timezone = 'Asia/Dhaka',
        public ?string $branchName = null,
    ) {}

    /** @param  int|null  $trialDays  the platform's `onboarding.trial_days`; null lets the plan decide */
    public function toProvisionData(?int $trialDays = null): ProvisionTenantData
    {
        return new ProvisionTenantData(
            name: $this->clinicName,
            slug: $this->slug,
            planCode: $this->planCode,
            ownerName: $this->ownerName,
            ownerEmail: $this->ownerEmail,
            ownerMobile: $this->ownerMobile,
            adminEmail: $this->ownerEmail,
            adminPassword: $this->adminPassword,
            demo: $this->demo,
            locale: $this->locale,
            timezone: $this->timezone,
            adminName: $this->ownerName,
            branchName: $this->branchName ?? $this->clinicName,
            trialDays: $trialDays,
        );
    }
}
