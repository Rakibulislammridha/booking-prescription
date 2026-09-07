<?php

declare(strict_types=1);

namespace App\Models\Catalog;

use App\Models\Catalog\Concerns\EnforcesCatalogReadOnly;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Patient-facing text behind /drug/{public_slug} (PRESCRIPTION.md §7.8).
 *
 * @property int $id
 * @property int $generic_id
 * @property string $public_slug
 * @property string|null $indications
 * @property string|null $indications_bn
 * @property string|null $side_effects
 * @property string|null $side_effects_bn
 * @property string|null $contraindications
 * @property string|null $precautions
 * @property string|null $patient_advice_bn
 * @property CarbonImmutable|null $published_at
 * @property bool $is_active
 */
final class DrugInformation extends CatalogModel
{
    use EnforcesCatalogReadOnly;

    protected $table = 'drug_information';

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['published_at' => 'immutable_datetime', 'is_active' => 'boolean'];
    }

    /** @param  Builder<DrugInformation>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /** @param  Builder<DrugInformation>  $query */
    public function scopePublished(Builder $query): void
    {
        $query->whereNotNull('published_at');
    }

    /** @return BelongsTo<Generic, $this> */
    public function generic(): BelongsTo
    {
        return $this->belongsTo(Generic::class);
    }
}
