<?php

declare(strict_types=1);

namespace App\Models\Catalog;

use App\Domain\Catalog\Enums\EvidenceLevel;
use App\Domain\Catalog\Enums\InteractionSeverity;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Generic ↔ generic pair, stored with generic_a_id < generic_b_id (SCHEMA §4).
 *
 * @property int $id
 * @property int $generic_a_id
 * @property int $generic_b_id
 * @property InteractionSeverity $severity
 * @property string|null $mechanism
 * @property string $effect
 * @property string|null $management
 * @property EvidenceLevel|null $evidence_level
 * @property string|null $source
 * @property bool $is_active
 */
final class DrugInteraction extends CatalogModel
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['severity' => InteractionSeverity::class, 'evidence_level' => EvidenceLevel::class, 'is_active' => 'boolean'];
    }

    /** @param  Builder<DrugInteraction>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Lookup with the pair in either order.
     *
     * @param  Builder<DrugInteraction>  $query
     */
    public function scopeForPair(Builder $query, int $a, int $b): void
    {
        $query->where('generic_a_id', min($a, $b))->where('generic_b_id', max($a, $b));
    }

    /** @return BelongsTo<Generic, $this> */
    public function genericA(): BelongsTo
    {
        return $this->belongsTo(Generic::class, 'generic_a_id');
    }

    /** @return BelongsTo<Generic, $this> */
    public function genericB(): BelongsTo
    {
        return $this->belongsTo(Generic::class, 'generic_b_id');
    }
}
