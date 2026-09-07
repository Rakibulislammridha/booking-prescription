<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Safety;

use App\Domain\Catalog\Services\CatalogCache;

/** Combination generics expand through generics.components [{generic_id, mg}] (CATALOG.md §2). */
final class GenericExpander
{
    public function __construct(private readonly CatalogCache $catalog) {}

    /**
     * The generic itself plus its components, with the mg share of each component per 1 mg of the combination
     * (null when unknown).
     *
     * @return array<int, float|null> generic id → mg fraction (self = 1.0)
     */
    public function expand(int $genericId): array
    {
        $row = $this->catalog->generic($genericId);
        $result = [$genericId => 1.0];
        $components = is_array($row['components'] ?? null) ? $row['components'] : [];
        $total = array_sum(array_map(fn ($c) => (float) ($c['mg'] ?? 0), $components));

        foreach ($components as $component) {
            if (! isset($component['generic_id'])) {
                continue;
            }

            $id = (int) $component['generic_id'];
            $result[$id] = $total > 0 && isset($component['mg']) ? (float) $component['mg'] / $total : null;
        }

        return $result;
    }

    /** @return list<int> */
    public function ids(int $genericId): array
    {
        return array_keys($this->expand($genericId));
    }

    public function name(int $genericId): string
    {
        return (string) ($this->catalog->generic($genericId)['name'] ?? "generic #{$genericId}");
    }
}
