<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Data;

/**
 * One Rx line as the safety checks see it (PRESCRIPTION.md §5.1 SafetyContext.items): generic ids only (I2),
 * dose facts, and the pre-loaded custom-brand row so checks stay pure w.r.t. tenant data.
 */
final readonly class SafetyItem
{
    /**
     * @param  array<string, mixed>|null  $customBrand  {id, exists, is_active, review_status, generic_id, deleted}
     */
    public function __construct(
        public string $key,
        public ?int $genericId,
        public ?int $brandId,
        public ?int $customBrandId,
        public ?int $strengthId,
        public ?float $strengthMg,
        public ?float $perMl,
        public ?string $formCode,
        public ?string $routeCode,
        public ?int $routeId,
        public ?ParsedLine $doseJson,
        public ?float $dailyMg,
        public ?float $perDoseMg,
        public string $genericName = '',
        public ?string $brandName = null,
        public bool $isSystemic = true,
        public ?array $customBrand = null,
        public bool $hasParseErrors = false,
    ) {}

    public function displayName(): string
    {
        return $this->brandName !== null ? "{$this->brandName} ({$this->genericName})" : $this->genericName;
    }
}
