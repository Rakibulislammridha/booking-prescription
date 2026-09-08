<?php

declare(strict_types=1);

namespace App\Models\Catalog;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $code
 * @property string $title
 * @property string|null $title_bn
 * @property string|null $chapter
 * @property string|null $block
 * @property string|null $parent_code
 * @property array<int, string> $aliases
 * @property bool $is_billable
 * @property bool $is_active
 */
final class Icd10Code extends CatalogModel
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['aliases' => 'array', 'is_billable' => 'boolean', 'is_active' => 'boolean'];
    }

    /** @param  Builder<Icd10Code>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /** @return BelongsTo<Icd10Code, $this> */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_code', 'code');
    }

    /** @return HasMany<Icd10Code, $this> */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_code', 'code');
    }
}
