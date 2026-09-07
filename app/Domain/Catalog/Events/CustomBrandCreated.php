<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A tenant added a custom brand — creation is the promotion submission (CATALOG.md §8). Consumed by
 * QueueCustomBrandForPromotion, which writes public.custom_brand_promotions.
 *
 * @property array<string, mixed> $snapshot
 */
final class CustomBrandCreated implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /** @param  array<string, mixed>  $snapshot */
    public function __construct(
        public readonly int $tenantId,
        public readonly int $customBrandId,
        public readonly array $snapshot,
    ) {}
}
