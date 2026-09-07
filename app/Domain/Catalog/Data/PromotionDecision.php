<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Data;

use Illuminate\Foundation\Http\FormRequest;

/** POST …/promotions/{promotion}/approve body (CATALOG.md §8): map onto an existing master brand, or create one. */
final readonly class PromotionDecision
{
    public function __construct(
        public string $mode,                     // map | create
        public ?int $brandId = null,             // map: the master brand to link
        public ?string $manufacturer = null,     // create: overrides the snapshot's manufacturer
        public ?string $presentations = null,    // create: `form:label[:pack]; …` (default: the snapshot's form + strength)
        public ?string $note = null,
    ) {}

    public static function fromRequest(FormRequest $request): self
    {
        $v = $request->validated();

        return new self(
            mode: (string) $v['mode'],
            brandId: isset($v['brand_id']) && $v['brand_id'] !== '' ? (int) $v['brand_id'] : null,
            manufacturer: isset($v['manufacturer']) && $v['manufacturer'] !== '' ? (string) $v['manufacturer'] : null,
            presentations: isset($v['presentations']) && $v['presentations'] !== '' ? (string) $v['presentations'] : null,
            note: isset($v['note']) && $v['note'] !== '' ? (string) $v['note'] : null,
        );
    }
}
