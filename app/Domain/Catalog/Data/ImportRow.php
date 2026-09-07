<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Data;

/**
 * One product presentation as mapped from a source file (CATALOG.md §5.2).
 */
final readonly class ImportRow
{
    /**
     * @param  list<string>  $aliases  brand aliases (seed only)
     */
    public function __construct(
        public int $sourceRow,
        public ?string $manufacturer,
        public string $brand,
        public string $genericText,
        public ?string $strengthLabel,
        public ?string $formText,
        public ?string $routeText = null,
        public ?string $packSize = null,
        public ?string $darNumber = null,
        public string $status = 'active',          // active | discontinued
        public ?string $genericSlug = null,        // seed rows reference generics.csv directly
        public ?int $popularity = null,
        public array $aliases = [],
        public ?int $unitPricePaisa = null,
    ) {}

    public function isDiscontinued(): bool
    {
        return in_array(strtolower($this->status), ['discontinued', 'inactive', 'cancelled', 'withdrawn', '0', 'false'], true);
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return [
            'manufacturer' => $this->manufacturer, 'brand' => $this->brand, 'generic_text' => $this->genericText,
            'strength_label' => $this->strengthLabel, 'form_text' => $this->formText, 'route_text' => $this->routeText,
            'pack_size' => $this->packSize, 'dar_number' => $this->darNumber,
        ];
    }
}
