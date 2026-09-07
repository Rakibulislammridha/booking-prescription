<?php

declare(strict_types=1);

namespace App\Models\Catalog;

use App\Domain\Catalog\Enums\DosePopulation;
use App\Models\Catalog\Concerns\EnforcesCatalogReadOnly;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $generic_id
 * @property int|null $route_id
 * @property DosePopulation $population
 * @property string|null $max_mg_per_day
 * @property string|null $max_mg_per_kg_per_day
 * @property string|null $max_mg_per_dose
 * @property int|null $min_age_months
 * @property int|null $max_age_months
 * @property string|null $notes
 * @property bool $is_active
 */
final class MaxDailyDose extends CatalogModel
{
    use EnforcesCatalogReadOnly;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['population' => DosePopulation::class, 'min_age_months' => 'integer', 'max_age_months' => 'integer', 'is_active' => 'boolean'];
    }

    /** @return BelongsTo<Generic, $this> */
    public function generic(): BelongsTo
    {
        return $this->belongsTo(Generic::class);
    }

    /** @return BelongsTo<Route, $this> */
    public function route(): BelongsTo
    {
        return $this->belongsTo(Route::class);
    }
}
