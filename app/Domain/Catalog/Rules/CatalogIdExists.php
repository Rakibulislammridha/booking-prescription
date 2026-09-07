<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Rules;

use App\Domain\Catalog\Services\CatalogCache;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Soft-reference validation against the catalog database through the version-keyed cache (CATALOG.md §7,
 * ARCHITECTURE.md §8.3). Meilisearch is never used for validation. icd10_codes are validated by `code`.
 */
final class CatalogIdExists implements ValidationRule
{
    /** @param  'generics'|'brands'|'strengths'|'allergy_classes'|'icd10_codes'|'dosage_forms'|'routes'  $table */
    public function __construct(private readonly string $table, private readonly bool $requireActive = true) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $key = $this->table === 'icd10_codes' ? (is_string($value) ? $value : null) : (is_numeric($value) ? (int) $value : null);

        if ($key === null || $key === '' || $key === 0) {
            $fail('catalog.validation.not_in_catalog')->translate();

            return;
        }

        $row = app(CatalogCache::class)->row($this->table, $key);

        if ($row === null) {
            $fail('catalog.validation.not_in_catalog')->translate();

            return;
        }

        if ($this->requireActive && ! (bool) ($row['is_active'] ?? false)) {
            $fail('catalog.validation.discontinued')->translate();
        }
    }
}
