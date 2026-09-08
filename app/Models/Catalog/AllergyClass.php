<?php

declare(strict_types=1);

namespace App\Models\Catalog;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property array<int, array{allergy_class_id: int, probability_pct: int}> $cross_reacts_with
 * @property bool $is_active
 */
final class AllergyClass extends CatalogModel
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['cross_reacts_with' => 'array', 'is_active' => 'boolean'];
    }

    /** @param  Builder<AllergyClass>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /** @return BelongsToMany<Generic, $this> */
    public function generics(): BelongsToMany
    {
        return $this->belongsToMany(Generic::class, 'allergy_class_generics');
    }
}
