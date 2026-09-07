<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Data;

/**
 * What the shorthand parser knows about the picked presentation (PRESCRIPTION.md §2.15). Built from the search
 * document / catalog strength row (DrugRefResolver) or from a custom brand. `per_ml` follows the catalog: mg per ml.
 */
final readonly class ParseContext
{
    public function __construct(
        public ?string $formCode = null,          // dosage_forms.code; null = prescribed by generic (form unknown)
        public string $defaultUnit = 'tab',
        public ?float $packSize = null,           // ml / actuations / units per pack when known
        public ?string $packUnit = null,          // bottle | inhaler | vial | pen | tube | pack …
        public ?float $strengthMg = null,         // mg per counted unit (or per label numerator on liquids)
        public ?float $perMl = null,              // mg per ml (liquids)
        public bool $isLiquid = false,
        public int $contDays = 30,
        public string $locale = 'en',
        public ?string $routeCode = null,         // presentation default route (display only)
        public ?string $strengthLabel = null,     // for messages ("500 mg tablet cannot give 300 mg")
        public ?string $formLabel = null,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'form_code' => $this->formCode, 'default_unit' => $this->defaultUnit, 'pack_size' => $this->packSize, 'pack_unit' => $this->packUnit,
            'strength_mg' => $this->strengthMg, 'per_ml' => $this->perMl, 'is_liquid' => $this->isLiquid, 'cont_days' => $this->contDays,
            'locale' => $this->locale, 'route_code' => $this->routeCode, 'strength_label' => $this->strengthLabel, 'form_label' => $this->formLabel,
        ];
    }

    /** @param  array<string, mixed>  $a */
    public static function fromArray(array $a): self
    {
        return new self(
            formCode: isset($a['form_code']) ? (string) $a['form_code'] : null,
            defaultUnit: (string) ($a['default_unit'] ?? 'tab'),
            packSize: isset($a['pack_size']) ? (float) $a['pack_size'] : null,
            packUnit: isset($a['pack_unit']) ? (string) $a['pack_unit'] : null,
            strengthMg: isset($a['strength_mg']) ? (float) $a['strength_mg'] : null,
            perMl: isset($a['per_ml']) ? (float) $a['per_ml'] : null,
            isLiquid: (bool) ($a['is_liquid'] ?? false),
            contDays: (int) ($a['cont_days'] ?? 30),
            locale: (string) ($a['locale'] ?? 'en'),
            routeCode: isset($a['route_code']) ? (string) $a['route_code'] : null,
            strengthLabel: isset($a['strength_label']) ? (string) $a['strength_label'] : null,
            formLabel: isset($a['form_label']) ? (string) $a['form_label'] : null,
        );
    }
}
