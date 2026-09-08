<?php

declare(strict_types=1);

namespace App\Models\Catalog;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Bangladeshi product name, always linked to one generic (SCHEMA §4).
 *
 * @property int $id
 * @property int $generic_id
 * @property string $name
 * @property string $slug
 * @property string|null $manufacturer
 * @property string|null $dar_number
 * @property int $popularity
 * @property array<int, string> $aliases
 * @property CarbonImmutable|null $discontinued_at
 * @property int|null $catalog_version_id
 * @property bool $is_active
 */
final class Brand extends CatalogModel
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['aliases' => 'array', 'popularity' => 'integer', 'discontinued_at' => 'immutable_datetime', 'is_active' => 'boolean'];
    }

    /** @param  Builder<Brand>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /** @return BelongsTo<Generic, $this> */
    public function generic(): BelongsTo
    {
        return $this->belongsTo(Generic::class);
    }

    /** @return HasMany<Strength, $this> */
    public function strengths(): HasMany
    {
        return $this->hasMany(Strength::class);
    }

    /** @return BelongsTo<CatalogVersion, $this> */
    public function catalogVersion(): BelongsTo
    {
        return $this->belongsTo(CatalogVersion::class);
    }
}
