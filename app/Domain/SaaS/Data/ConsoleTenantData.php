<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Data;

use App\Domain\Tenancy\Data\ProvisionTenantData;

/**
 * What the super console collects to onboard a clinic on a customer's behalf (BRIEF §5.M). It is the wizard's
 * `OnboardingData` plus the things an operator knows and a self-serving customer does not: a Bangla name, a trial
 * length negotiated by hand, a custom domain agreed in advance, and internal notes. `adminPassword` is null when
 * the operator chose to hand the owner a set-password link instead of typing a password for them.
 */
final readonly class ConsoleTenantData
{
    public function __construct(
        public string $name,
        public ?string $nameBn,
        public string $slug,
        public string $planCode,
        public ?int $trialDays,
        public string $locale,
        public string $timezone,
        public string $ownerName,
        public string $ownerEmail,
        public string $ownerMobile,
        public string $adminName,
        public string $adminEmail,
        public ?string $adminPassword,
        public bool $demo = false,
        public ?string $customDomain = null,
        public ?string $branchName = null,
        public ?string $notes = null,
    ) {}

    /** The same provisioning input the public wizard builds — there is exactly one provisioning path (ARCHITECTURE §4.4). */
    public function toProvisionData(string $adminPassword): ProvisionTenantData
    {
        return new ProvisionTenantData(
            name: $this->name,
            slug: $this->slug,
            planCode: $this->planCode,
            ownerName: $this->ownerName,
            ownerEmail: $this->ownerEmail,
            ownerMobile: $this->ownerMobile,
            adminEmail: $this->adminEmail,
            adminPassword: $adminPassword,
            customDomain: $this->customDomain,
            demo: $this->demo,
            locale: $this->locale,
            timezone: $this->timezone,
            adminName: $this->adminName,
            branchName: $this->branchName ?? $this->name,
        );
    }
}
