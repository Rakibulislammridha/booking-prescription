<?php

declare(strict_types=1);

namespace App\Models\Catalog;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Brand + strength + form (+ route) — the unit a doctor picks; one Meilisearch document each (SCHEMA §4).
 *
 * @property int $id
 * @property int $brand_id
 * @property int $generic_id
 * @property int $dosage_form_id
 * @property int|null $route_id
 * @property string $strength_label
 * @property string|null $strength_value
 * @property string|null $strength_unit
 * @property string|null $per_volume_ml
 * @property string|null $pack_size
 * @property int|null $unit_price_paisa
 * @property string|null $strength_mg
 * @property string|null $per_ml
 * @property string|null $pack_size_value
 * @property string|null $pack_unit
 * @property int|null $catalog_version_id
 * @property bool $is_active
 */
final class Strength extends CatalogModel
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'unit_price_paisa' => 'integer'];
    }

    /** @param  Builder<Strength>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /** @return BelongsTo<Brand, $this> */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /** @return BelongsTo<Generic, $this> */
    public function generic(): BelongsTo
    {
        return $this->belongsTo(Generic::class);
    }

    /** @return BelongsTo<DosageForm, $this> */
    public function dosageForm(): BelongsTo
    {
        return $this->belongsTo(DosageForm::class);
    }

    /** @return BelongsTo<Route, $this> */
    public function route(): BelongsTo
    {
        return $this->belongsTo(Route::class);
    }
}
