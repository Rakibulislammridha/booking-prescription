<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Data;

use JsonSerializable;

/** Output of DrugSearchService::search(): the merged, re-ranked list (PRESCRIPTION.md §3.3 step 5). */
final readonly class DrugSearchResult implements JsonSerializable
{
    /** @param  list<array<string, mixed>>  $hits */
    public function __construct(
        public string $q,
        public int $tookMs,
        public array $hits,
        public string $engine = 'meilisearch',
    ) {}

    /** @return array{q: string, took_ms: int, hits: list<array<string, mixed>>, engine: string} */
    public function jsonSerialize(): array
    {
        return ['q' => $this->q, 'took_ms' => $this->tookMs, 'hits' => $this->hits, 'engine' => $this->engine];
    }
}
