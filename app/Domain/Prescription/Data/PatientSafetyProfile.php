<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Data;

/**
 * The de-identified patient facts the checks reason over (PRESCRIPTION.md §5.1/§5.3): age, weight, sex, the
 * condition-derived flags (Z33.1 / O-chapter → pregnant, Z39.1 → lactating, N17–N19 → renal, K70–K77/B18 → hepatic),
 * structured and free-text allergies and the active medication generics.
 */
final readonly class PatientSafetyProfile
{
    /**
     * @param  list<int>  $allergyGenericIds
     * @param  list<int>  $allergyClassIds
     * @param  list<array{name: string, severity: string|null, reaction: string|null}>  $allergyTexts  food / environmental / other rows
     * @param  array<int, string|null>  $allergySeverities  generic or class id → severity
     * @param  list<int>  $currentMedicationGenericIds
     * @param  array<int, string>  $currentMedicationNames  generic id → display name
     */
    public function __construct(
        public ?int $ageMonths,
        public ?float $weightKg,
        public ?string $sex,
        public bool $isPregnant,
        public bool $isLactating,
        public ?int $trimester,
        public bool $renalImpairment,
        public bool $hepaticImpairment,
        public array $allergyGenericIds = [],
        public array $allergyClassIds = [],
        public array $allergyTexts = [],
        public array $allergySeverities = [],
        public array $currentMedicationGenericIds = [],
        public array $currentMedicationNames = [],
        public bool $pregnancyStatusKnown = false,
    ) {}

    public function ageYears(): ?float
    {
        return $this->ageMonths === null ? null : $this->ageMonths / 12;
    }

    /** age < 12 y, or weight < 40 kg under 18 y (PRESCRIPTION.md §5.3 PediatricDoseCheck). */
    public function isPediatric(): bool
    {
        if ($this->ageMonths === null) {
            return false;
        }

        return $this->ageMonths < 144 || ($this->weightKg !== null && $this->weightKg < 40 && $this->ageMonths < 216);
    }

    public function isElderly(): bool
    {
        return $this->ageMonths !== null && $this->ageMonths >= 65 * 12;
    }

    /** Female 10–55 y with unknown pregnancy status. */
    public function pregnancyStatusUnknownForFertileFemale(): bool
    {
        return $this->sex === 'female' && ! $this->pregnancyStatusKnown && ! $this->isPregnant
            && $this->ageMonths !== null && $this->ageMonths >= 120 && $this->ageMonths <= 55 * 12;
    }
}
