<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Data;

/**
 * Result of StrengthLabelParser (CATALOG.md §5.3): the numbers the safety maths and the quantity calculator read at
 * runtime so labels are never parsed outside the importer.
 *
 * @property-read float|null $strengthMg  mg (or IU) per counting unit; null for percentages
 * @property-read float|null $perMl       mg (or IU) per ml for liquids / percentages
 */
final readonly class ParsedStrength
{
    /**
     * @param  list<float>  $components  every amount in a combination label (25/125 mcg → [25, 125])
     */
    public function __construct(
        public string $label,
        public float $amountValue,
        public string $amountUnit,
        public ?float $perValue,
        public ?string $perUnit,
        public ?float $strengthMg,
        public ?float $perMl,
        public ?string $modifier = null,
        public array $components = [],
    ) {}

    /** SCHEMA strengths.strength_unit: `mg`, `mg/ml`, `IU`, `%` … */
    public function strengthUnit(): string
    {
        return $this->perUnit === null ? $this->amountUnit : $this->amountUnit.'/'.$this->perUnit;
    }

    /** SCHEMA strengths.per_volume_ml — the denominator when it is a volume. */
    public function perVolumeMl(): ?float
    {
        return $this->perUnit === 'ml' ? $this->perValue : null;
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'label' => $this->label, 'amount_value' => $this->amountValue, 'amount_unit' => $this->amountUnit,
            'per_value' => $this->perValue, 'per_unit' => $this->perUnit, 'strength_mg' => $this->strengthMg,
            'per_ml' => $this->perMl, 'modifier' => $this->modifier, 'components' => $this->components,
        ];
    }
}
