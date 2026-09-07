<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Data;

/**
 * Input of DrugSearchService::search() (PRESCRIPTION.md §3.3). A trailing number in q is lifted into strengthMg
 * (`nap 500` → q "nap", strengthMg 500) by DrugSearchService.
 */
final readonly class DrugSearchQuery
{
    /** @param  list<string>  $dxCodes */
    public function __construct(
        public string $q,
        public ?int $doctorId = null,
        public array $dxCodes = [],
        public ?float $strengthMg = null,
        public int $limit = 12,
    ) {}

    /** @return array{0: string, 1: float|null} */
    public static function splitTrailingNumber(string $q): array
    {
        $q = trim($q);

        if (preg_match('/^(.*\S)\s+(\d+(?:\.\d+)?)$/u', $q, $m)) {
            return [$m[1], (float) $m[2]];
        }

        return [$q, null];
    }
}
