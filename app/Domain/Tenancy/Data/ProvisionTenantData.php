<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Data;

/**
 * Input of ProvisionTenant (ARCHITECTURE §4.4). id/schemaName are only set by the test harness (9001/tenant_test_a).
 */
final readonly class ProvisionTenantData
{
    public function __construct(
        public string $name,
        public string $slug,
        public string $planCode,
        public string $ownerName,
        public string $ownerEmail,
        public string $ownerMobile,
        public string $adminEmail,
        public string $adminPassword,
        public ?string $customDomain = null,
        public bool $demo = false,
        public ?int $id = null,
        public ?string $schemaName = null,
        public string $locale = 'bn',
        public string $timezone = 'Asia/Dhaka',
        public ?string $adminName = null,
        public ?string $branchName = null,
        public ?string $branchCode = null,
    ) {}

    public static function forTests(int $id, string $slug, string $schemaName, string $planCode = 'starter'): self
    {
        return new self(
            name: 'Test Clinic '.strtoupper(substr($slug, -1)),
            slug: $slug,
            planCode: $planCode,
            ownerName: 'Test Owner',
            ownerEmail: "owner@{$slug}.test",
            ownerMobile: '+8801700000000',
            adminEmail: "admin@{$slug}.test",
            adminPassword: 'password',
            id: $id,
            schemaName: $schemaName,
        );
    }
}
