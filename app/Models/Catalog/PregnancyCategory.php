<?php

declare(strict_types=1);

namespace App\Models\Catalog;

use App\Domain\Catalog\Enums\LactationRisk;
use App\Domain\Catalog\Enums\PregnancyCategory as Category;
use App\Models\Catalog\Concerns\EnforcesCatalogReadOnly;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $generic_id
 * @property int|null $trimester
 * @property Category $category
 * @property LactationRisk $lactation
 * @property string|null $notes
 * @property bool $is_active
 */
final class PregnancyCategory extends CatalogModel
{
    use EnforcesCatalogReadOnly;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['trimester' => 'integer', 'category' => Category::class, 'lactation' => LactationRisk::class, 'is_active' => 'boolean'];
    }

    /** @return BelongsTo<Generic, $this> */
    public function generic(): BelongsTo
    {
        return $this->belongsTo(Generic::class);
    }
}
