<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Safety;

use App\Domain\Prescription\Data\PatientSafetyProfile;
use App\Domain\Prescription\Data\SafetyItem;
use App\Domain\Prescription\Enums\SafetyStage;

/** PRESCRIPTION.md §5.1 — built from generic ids only (I2). */
final class SafetyContext
{
    /** @var array<string, array{daily_mg?: float|null, per_dose_mg?: float|null, mg_per_kg_day?: float|null, adult_max_mg_day?: float|null}> filled by checks */
    public array $computed = [];

    /**
     * @param  list<SafetyItem>  $items
     * @param  array<string, array{reason: string, by: int|null, at: string|null}>  $overrides  fingerprint → override
     */
    public function __construct(
        public readonly int $prescriptionId,
        public readonly SafetyStage $stage,
        public readonly PatientSafetyProfile $patient,
        public readonly array $items,
        public readonly array $overrides = [],
    ) {}

    /**
     * Items with a live generic id (drug-less / unresolved lines are skipped by the drug-level checks).
     *
     * @return list<SafetyItem>
     */
    public function itemsWithGeneric(): array
    {
        return array_values(array_filter($this->items, fn (SafetyItem $i) => $i->genericId !== null));
    }

    /** @param  array<string, mixed>  $values */
    public function compute(string $itemKey, array $values): void
    {
        $this->computed[$itemKey] = array_merge($this->computed[$itemKey] ?? [], $values);
    }
}
