<?php

declare(strict_types=1);

namespace App\Models\Catalog;

use App\Domain\Catalog\Enums\CautionLevel;
use App\Models\Catalog\Concerns\EnforcesCatalogReadOnly;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $generic_id
 * @property string|null $child_pugh_class
 * @property CautionLevel $level
 * @property string $advice
 * @property bool $is_active
 */
final class HepaticCaution extends CatalogModel
{
    use EnforcesCatalogReadOnly;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['level' => CautionLevel::class, 'is_active' => 'boolean'];
    }

    /** @return BelongsTo<Generic, $this> */
    public function generic(): BelongsTo
    {
        return $this->belongsTo(Generic::class);
    }
}
