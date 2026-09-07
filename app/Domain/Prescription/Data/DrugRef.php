<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Data;

/**
 * The committed drug chip (PRESCRIPTION.md §1.5 DrugRef) as the server resolves it from catalog / custom-brand rows:
 * soft ids + snapshot text + the presentation facts the parser and safety maths need. generic_id is ALWAYS present
 * (I2, I4) — a DrugRef without a live generic is `resolved = false` and the line is blocked.
 */
final readonly class DrugRef
{
    public function __construct(
        public string $kind,                       // presentation | generic | custom
        public ?int $genericId,
        public ?int $brandId,
        public ?int $customBrandId,
        public ?int $strengthId,
        public string $genericName,
        public ?string $brandName,
        public ?string $strength,
        public ?string $form,
        public ?string $formCode,
        public ?string $route,
        public ?string $routeCode,
        public ?int $routeId = null,
        public ?int $dosageFormId = null,
        public ?float $packSize = null,
        public ?string $packUnit = null,
        public ?float $strengthMg = null,
        public ?float $perMl = null,
        public bool $isLiquid = false,
        public ?string $infoSlug = null,
        public ?string $therapeuticClass = null,
        public bool $isSystemic = true,
        public bool $genericActive = true,
        public bool $resolved = true,
        public ?string $unresolved = null,         // which reference failed: generic | brand | strength | custom_brand
        public ?string $manufacturer = null,
        public string $defaultUnit = 'tab',
    ) {}

    /** Search-document id: s{strength} | c{custom} | g{generic}. */
    public function presentationKey(): string
    {
        if ($this->customBrandId !== null) {
            return 'c'.$this->customBrandId;
        }

        if ($this->strengthId !== null) {
            return 's'.$this->strengthId;
        }

        return 'g'.$this->genericId;
    }

    /** "Napa 500 mg Tab" / "Paracetamol (any brand)". */
    public function label(): string
    {
        if ($this->brandName === null) {
            return $this->genericName.' (any brand)';
        }

        return trim($this->brandName.' '.($this->strength ?? '').' '.($this->form ?? ''));
    }

    public function parseContext(int $contDays = 30, string $locale = 'en'): ParseContext
    {
        return new ParseContext(
            formCode: $this->formCode,
            defaultUnit: $this->defaultUnit,
            packSize: $this->packSize,
            packUnit: $this->packUnit,
            strengthMg: $this->strengthMg,
            perMl: $this->perMl,
            isLiquid: $this->isLiquid,
            contDays: $contDays,
            locale: $locale,
            routeCode: $this->routeCode,
            strengthLabel: $this->strength,
            formLabel: $this->form !== null ? mb_strtolower($this->form) : null,
        );
    }

    /** @return array<string, mixed> the wire shape the writer echoes (PRESCRIPTION.md §1.5) */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind, 'generic_id' => $this->genericId, 'brand_id' => $this->brandId, 'custom_brand_id' => $this->customBrandId,
            'strength_id' => $this->strengthId, 'generic_name' => $this->genericName, 'brand_name' => $this->brandName, 'strength' => $this->strength,
            'form' => $this->form, 'form_code' => $this->formCode, 'route' => $this->route, 'route_code' => $this->routeCode,
            'pack_size' => $this->packSize, 'pack_unit' => $this->packUnit, 'strength_mg' => $this->strengthMg, 'per_ml' => $this->perMl,
            'info_slug' => $this->infoSlug, 'label' => $this->label(),
        ];
    }

    /** @return array<string, mixed> the prescription_items snapshot columns */
    public function snapshotColumns(): array
    {
        return [
            'generic_id' => $this->genericId, 'brand_id' => $this->brandId, 'strength_id' => $this->strengthId, 'custom_brand_id' => $this->customBrandId,
            'generic_name' => $this->genericName, 'brand_name' => $this->brandName, 'strength' => $this->strength, 'form' => $this->form,
            'route' => $this->route, 'info_url_slug' => $this->infoSlug,
        ];
    }
}
