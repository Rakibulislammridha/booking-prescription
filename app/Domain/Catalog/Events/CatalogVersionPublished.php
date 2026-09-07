<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Raised after a catalog import commits (CATALOG.md §5.7); consumed by SaaS for the super-admin notification.
 */
final class CatalogVersionPublished
{
    use Dispatchable;

    /**
     * @param  array<string, array{rows: int, inserted: int, updated: int, deactivated: int}>  $stats
     * @param  array<string, int>  $openIssues  kind → count
     */
    public function __construct(
        public readonly int $versionId,
        public readonly string $version,
        public readonly array $stats,
        public readonly array $openIssues,
    ) {}
}
