<?php

declare(strict_types=1);

namespace App\Models\Catalog;

use App\Domain\Catalog\Enums\CautionLevel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $generic_id
 * @property int|null $egfr_below
 * @property CautionLevel $level
 * @property string $advice
 * @property bool $is_active
 */
final class RenalCaution extends CatalogModel
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['egfr_below' => 'integer', 'level' => CautionLevel::class, 'is_active' => 'boolean'];
    }

    /** @return BelongsTo<Generic, $this> */
    public function generic(): BelongsTo
    {
        return $this->belongsTo(Generic::class);
    }
}
