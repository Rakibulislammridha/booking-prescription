<?php

declare(strict_types=1);

namespace App\Models\Catalog;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Membership row (created_at only, SCHEMA §4).
 *
 * @property int $id
 * @property int $allergy_class_id
 * @property int $generic_id
 * @property bool $is_active
 */
final class AllergyClassGeneric extends CatalogModel
{
    public const UPDATED_AT = null;

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    /** @return BelongsTo<AllergyClass, $this> */
    public function allergyClass(): BelongsTo
    {
        return $this->belongsTo(AllergyClass::class);
    }

    /** @return BelongsTo<Generic, $this> */
    public function generic(): BelongsTo
    {
        return $this->belongsTo(Generic::class);
    }
}
