<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Rules;

use App\Domain\Catalog\Services\CatalogCache;
use Closure;
use Illuminate\Contracts\Validation\DataAwareRule;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * strength.brand_id === brand_id && strength.generic_id === generic_id (CATALOG.md §7). Attach to the strength_id
 * field; the sibling field names default to brand_id / generic_id of the same array level.
 */
final class StrengthBelongsToBrand implements DataAwareRule, ValidationRule
{
    /** @var array<string, mixed> */
    private array $data = [];

    public function __construct(private readonly string $brandField = 'brand_id', private readonly string $genericField = 'generic_id') {}

    /** @param  array<string, mixed>  $data */
    public function setData(array $data): static
    {
        $this->data = $data;

        return $this;
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_numeric($value)) {
            return;                                                     // CatalogIdExists reports missing ids
        }

        $strength = app(CatalogCache::class)->strength((int) $value);

        if ($strength === null) {
            return;
        }

        $prefix = str_contains($attribute, '.') ? substr($attribute, 0, strrpos($attribute, '.') + 1) : '';
        $brandId = data_get($this->data, $prefix.$this->brandField);
        $genericId = data_get($this->data, $prefix.$this->genericField);

        if ($brandId !== null && (int) $brandId !== (int) $strength['brand_id']) {
            $fail('catalog.validation.strength_brand_mismatch')->translate();

            return;
        }

        if ($genericId !== null && (int) $genericId !== (int) $strength['generic_id']) {
            $fail('catalog.validation.strength_generic_mismatch')->translate();
        }
    }
}
