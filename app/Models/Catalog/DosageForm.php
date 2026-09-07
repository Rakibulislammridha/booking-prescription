<?php

declare(strict_types=1);

namespace App\Models\Catalog;

use App\Domain\Catalog\Enums\DosageFormCode;
use App\Models\Catalog\Concerns\EnforcesCatalogReadOnly;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $name
 * @property string|null $name_bn
 * @property string $abbreviation
 * @property string $default_unit
 * @property int|null $default_route_id
 * @property DosageFormCode $code
 * @property bool $is_liquid
 * @property string|null $pack_unit
 * @property bool $is_active
 */
final class DosageForm extends CatalogModel
{
    use EnforcesCatalogReadOnly;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['code' => DosageFormCode::class, 'is_liquid' => 'boolean', 'is_active' => 'boolean'];
    }

    /** @param  Builder<DosageForm>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /** @return BelongsTo<Route, $this> */
    public function defaultRoute(): BelongsTo
    {
        return $this->belongsTo(Route::class, 'default_route_id');
    }
}
