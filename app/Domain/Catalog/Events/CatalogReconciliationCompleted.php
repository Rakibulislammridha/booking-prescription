<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** Summary of one nightly run (CATALOG.md §6.5) → SaaS dashboard tile + email when orphans > 0. */
final class CatalogReconciliationCompleted
{
    use Dispatchable;

    public function __construct(
        public readonly string $runId,
        public readonly int $tenants,
        public readonly int $orphans,
        public readonly int $inactive,
    ) {}
}
