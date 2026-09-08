<?php

declare(strict_types=1);

namespace App\Models\Catalog;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Molecule / salt — every safety check keys on generics.id (SCHEMA §4).
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $atc_code
 * @property array<int, string> $aliases
 * @property string|null $therapeutic_class
 * @property bool $is_controlled
 * @property bool $is_pediatric_weight_based
 * @property string|null $name_bn
 * @property array<int, array{generic_id: int, mg: float|null}>|null $components
 * @property bool $needs_review
 * @property int|null $catalog_version_id
 * @property bool $is_active
 */
final class Generic extends CatalogModel
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['aliases' => 'array', 'components' => 'array', 'is_controlled' => 'boolean', 'is_pediatric_weight_based' => 'boolean', 'needs_review' => 'boolean', 'is_active' => 'boolean'];
    }

    /** @param  Builder<Generic>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /** @return HasMany<Brand, $this> */
    public function brands(): HasMany
    {
        return $this->hasMany(Brand::class);
    }

    /** @return HasMany<Strength, $this> */
    public function strengths(): HasMany
    {
        return $this->hasMany(Strength::class);
    }

    /** @return HasOne<DrugInformation, $this> */
    public function information(): HasOne
    {
        return $this->hasOne(DrugInformation::class);
    }

    /** @return BelongsToMany<AllergyClass, $this> */
    public function allergyClasses(): BelongsToMany
    {
        return $this->belongsToMany(AllergyClass::class, 'allergy_class_generics');
    }

    /** @return HasMany<PregnancyCategory, $this> */
    public function pregnancyCategories(): HasMany
    {
        return $this->hasMany(PregnancyCategory::class);
    }

    /** @return HasMany<RenalCaution, $this> */
    public function renalCautions(): HasMany
    {
        return $this->hasMany(RenalCaution::class);
    }

    /** @return HasMany<HepaticCaution, $this> */
    public function hepaticCautions(): HasMany
    {
        return $this->hasMany(HepaticCaution::class);
    }

    /** @return HasMany<MaxDailyDose, $this> */
    public function maxDailyDoses(): HasMany
    {
        return $this->hasMany(MaxDailyDose::class);
    }

    /** @return BelongsTo<CatalogVersion, $this> */
    public function catalogVersion(): BelongsTo
    {
        return $this->belongsTo(CatalogVersion::class);
    }
}
