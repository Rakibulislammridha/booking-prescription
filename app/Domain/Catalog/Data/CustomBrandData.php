<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Data;

use Illuminate\Foundation\Http\FormRequest;

/** Validated input of the custom-brand form (CATALOG.md §8 Create). */
final readonly class CustomBrandData
{
    public function __construct(
        public string $brandName,
        public int $genericId,
        public ?string $manufacturer = null,
        public ?string $strength = null,
        public ?int $dosageFormId = null,
        public ?int $routeId = null,
    ) {}

    public static function fromRequest(FormRequest $request): self
    {
        $v = $request->validated();

        return new self(
            brandName: trim((string) $v['brand_name']),
            genericId: (int) $v['generic_id'],
            manufacturer: isset($v['manufacturer']) && $v['manufacturer'] !== '' ? trim((string) $v['manufacturer']) : null,
            strength: isset($v['strength']) && $v['strength'] !== '' ? trim((string) $v['strength']) : null,
            dosageFormId: isset($v['dosage_form_id']) && $v['dosage_form_id'] !== '' ? (int) $v['dosage_form_id'] : null,
            routeId: isset($v['route_id']) && $v['route_id'] !== '' ? (int) $v['route_id'] : null,
        );
    }
}
