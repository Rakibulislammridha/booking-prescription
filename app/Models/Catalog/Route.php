<?php

declare(strict_types=1);

namespace App\Models\Catalog;

use App\Domain\Catalog\Enums\RouteCode;
use Illuminate\Database\Eloquent\Builder;

/**
 * @property int $id
 * @property string $name
 * @property string|null $name_bn
 * @property string $abbreviation
 * @property RouteCode $code
 * @property bool $is_systemic
 * @property bool $is_active
 */
final class Route extends CatalogModel
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['code' => RouteCode::class, 'is_systemic' => 'boolean', 'is_active' => 'boolean'];
    }

    /** @param  Builder<Route>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }
}
