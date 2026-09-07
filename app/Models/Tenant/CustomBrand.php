<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use App\Domain\Catalog\Enums\CustomBrandReviewStatus;
use App\Domain\Catalog\Import\StrengthLabelParser;
use App\Domain\Catalog\Services\CatalogCache;
use Carbon\CarbonImmutable;
use Database\Factories\Catalog\CustomBrandFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Laravel\Scout\Searchable;

/**
 * Tenant-added brand with a mandatory generic link (SCHEMA §3.4, PRESCRIPTION.md §3.2). Scout-searchable into
 * t{tenant_id}_custom_brands; the document mirrors catalog_drugs presentations with source = custom.
 *
 * @property int $id
 * @property int $generic_id
 * @property string $generic_name
 * @property string $brand_name
 * @property string|null $manufacturer
 * @property string|null $strength
 * @property int|null $dosage_form_id
 * @property string|null $form
 * @property int|null $route_id
 * @property string|null $route
 * @property CustomBrandReviewStatus $review_status
 * @property bool $promoted_to_master
 * @property int|null $master_brand_id
 * @property int|null $master_strength_id
 * @property string|null $review_note
 * @property CarbonImmutable|null $reviewed_at
 * @property int|null $created_by_user_id
 * @property int $use_count
 * @property bool $is_active
 * @property CarbonImmutable|null $deleted_at
 */
final class CustomBrand extends TenantModel
{
    /** @use HasFactory<CustomBrandFactory> */
    use HasFactory, Searchable, SoftDeletes;

    protected static string $factory = CustomBrandFactory::class;

    protected static bool $audited = true;

    protected $fillable = [
        'generic_id', 'generic_name', 'brand_name', 'manufacturer', 'strength', 'dosage_form_id', 'form', 'route_id', 'route',
        'review_status', 'promoted_to_master', 'master_brand_id', 'master_strength_id', 'review_note', 'reviewed_at',
        'created_by_user_id', 'use_count', 'is_active',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'review_status' => CustomBrandReviewStatus::class, 'promoted_to_master' => 'boolean', 'is_active' => 'boolean',
            'use_count' => 'integer', 'reviewed_at' => 'immutable_datetime',
        ];
    }

    /** @param  Builder<CustomBrand>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /** @param  Builder<CustomBrand>  $query */
    public function scopeUsable(Builder $query): void
    {
        $query->where('is_active', true)->where('review_status', '<>', CustomBrandReviewStatus::Rejected->value);
    }

    /** @return BelongsTo<User, $this> */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /** Usable in a prescription: active, not rejected, and the generic still resolves (BRIEF §3.4). */
    public function isUsable(): bool
    {
        if (! $this->is_active || $this->review_status === CustomBrandReviewStatus::Rejected || $this->deleted_at !== null) {
            return false;
        }

        $generic = app(CatalogCache::class)->generic($this->generic_id);

        return $generic !== null && (bool) $generic['is_active'];
    }

    // ---------------------------------------------------------------------------------------------------- Scout

    /** The Searchable trait would shadow TenantModel's tenant-prefixed uid; keep the tenant one (PRESCRIPTION.md §3.2). */
    public function searchableAs(): string
    {
        return parent::searchableAs();
    }

    public function shouldBeSearchable(): bool
    {
        return $this->is_active && $this->review_status !== CustomBrandReviewStatus::Rejected && $this->deleted_at === null;
    }

    /** Document ids are `c{id}` (SCHEMA §5.6). */
    public function getScoutKey(): string
    {
        return 'c'.$this->getKey();
    }

    public function getScoutKeyName(): string
    {
        return 'id';
    }

    /** @return array<string, mixed> */
    public function toSearchableArray(): array
    {
        $cache = app(CatalogCache::class);
        $form = $this->dosage_form_id !== null ? $cache->dosageForm($this->dosage_form_id) : null;
        $route = $this->route_id !== null ? $cache->route($this->route_id) : null;
        $generic = $cache->generic($this->generic_id);
        $str = app(StrengthLabelParser::class)->tryParse($this->strength);

        return [
            'id' => 'c'.$this->id, 'source' => 'custom', 'doc_type' => 'presentation', 'custom_brand_id' => $this->id,
            'label' => trim("{$this->brand_name} {$this->strength} ".($form['abbreviation'] ?? $this->form ?? '')),
            'generic_id' => $this->generic_id, 'generic_name' => $this->generic_name, 'generic_aliases' => $generic['aliases'] ?? [],
            'brand_id' => $this->master_brand_id, 'strength_id' => $this->master_strength_id,
            'brand_name' => $this->brand_name, 'manufacturer' => $this->manufacturer,
            'strength_label' => $this->strength, 'strength_value' => $str?->amountValue, 'strength_unit' => $str?->strengthUnit(),
            'per_volume_ml' => $str?->perVolumeMl(), 'strength_mg' => $str?->strengthMg, 'per_ml' => $str?->perMl,
            'dosage_form_id' => $this->dosage_form_id, 'form' => $this->form, 'form_code' => $form['code'] ?? null,
            'default_unit' => $form['default_unit'] ?? 'tab',
            'route_id' => $this->route_id, 'route' => $this->route, 'route_code' => $route['code'] ?? null,
            'pack_size' => null, 'pack_size_value' => null, 'pack_unit' => null,
            'info_slug' => null, 'therapeutic_class' => $generic['therapeutic_class'] ?? null, 'is_controlled' => (bool) ($generic['is_controlled'] ?? false),
            'review_status' => $this->review_status->value, 'promoted_to_master' => $this->promoted_to_master,
            'use_count' => $this->use_count, 'popularity' => $this->use_count, 'is_active' => $this->is_active,
        ];
    }
}
