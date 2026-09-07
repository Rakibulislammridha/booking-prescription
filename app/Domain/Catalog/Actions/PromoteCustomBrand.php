<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Data\PromotionDecision;
use App\Domain\Catalog\Enums\CatalogVersionStatus;
use App\Domain\Catalog\Enums\CustomBrandPromotionStatus;
use App\Domain\Catalog\Enums\CustomBrandReviewStatus;
use App\Domain\Catalog\Exceptions\CustomBrandNotReviewable;
use App\Domain\Catalog\Exceptions\GenericNotUsable;
use App\Domain\Catalog\Import\FormMapper;
use App\Domain\Catalog\Import\SeedRowMapper;
use App\Domain\Catalog\Import\StrengthLabelParser;
use App\Domain\Catalog\Search\CatalogSearchIndexer;
use App\Domain\Catalog\Services\CatalogCache;
use App\Domain\Catalog\Services\CatalogWriteContext;
use App\Models\Catalog\Brand;
use App\Models\Catalog\CatalogVersion;
use App\Models\Catalog\DosageForm;
use App\Models\Catalog\Generic;
use App\Models\Catalog\Strength;
use App\Models\Central\CustomBrandPromotion;
use App\Models\Central\Tenant;
use App\Models\Tenant\CustomBrand;
use App\Tenancy\Facades\Tenancy;
use Illuminate\Support\Str;

/**
 * Super-admin approval (CATALOG.md §8): master rows are created/mapped inside CatalogWriteContext under a
 * catalog_versions row (notes: promotion); then the tenant row is flagged promoted with the master ids and re-indexed.
 * Other tenants' identical pending brands are auto-resolved as `map`. Historical prescription rows are untouched.
 */
final class PromoteCustomBrand
{
    public function __construct(
        private readonly CatalogWriteContext $context,
        private readonly StrengthLabelParser $parser,
        private readonly FormMapper $formMapper,
        private readonly CatalogCache $cache,
    ) {}

    /** @return array{brand_id: int, strength_id: int|null, catalog_version_id: int} */
    public function handle(CustomBrandPromotion $promotion, PromotionDecision $decision, int $superAdminId): array
    {
        if ($promotion->status !== CustomBrandPromotionStatus::Pending->value) {
            throw new CustomBrandNotReviewable($promotion->status);
        }

        $master = $this->context->run(fn () => $this->writeMasterRows($promotion, $decision, $superAdminId), 'promotion');

        $this->cache->bumpVersion();

        if (config('scout.driver') === 'meilisearch') {
            app(CatalogSearchIndexer::class)->upsertChanged(['brands' => [$master['brand_id']], 'strengths' => array_filter([$master['strength_id']])]);
        }

        $promotion->forceFill([
            'status' => CustomBrandPromotionStatus::Promoted->value,
            'reviewed_by_super_admin_id' => $superAdminId,
            'reviewed_at' => now(),
            'decision' => ['mode' => $decision->mode, 'brand_id' => $master['brand_id'], 'strength_id' => $master['strength_id'], 'catalog_version_id' => $master['catalog_version_id'], 'reason' => $decision->note],
        ])->save();

        $this->applyToTenant($promotion, $master, $decision->note ?? 'promoted to master catalog');

        // Same brand name + generic pending elsewhere → resolved as map onto the rows just created.
        $siblings = CustomBrandPromotion::query()->where('id', '<>', $promotion->id)->where('status', CustomBrandPromotionStatus::Pending->value)
            ->where('generic_id', $promotion->getAttribute('generic_id'))->whereRaw('lower(brand_name) = ?', [Str::lower($promotion->brand_name)])->get();

        foreach ($siblings as $sibling) {
            $sibling->forceFill([
                'status' => CustomBrandPromotionStatus::Promoted->value, 'reviewed_by_super_admin_id' => $superAdminId, 'reviewed_at' => now(),
                'decision' => ['mode' => 'map', 'brand_id' => $master['brand_id'], 'strength_id' => $master['strength_id'], 'catalog_version_id' => $master['catalog_version_id'], 'reason' => 'auto-mapped with #'.$promotion->id],
            ])->save();
            $this->applyToTenant($sibling, $master, 'mapped to master catalog');
        }

        return $master;
    }

    /** @return array{brand_id: int, strength_id: int|null, catalog_version_id: int} */
    private function writeMasterRows(CustomBrandPromotion $promotion, PromotionDecision $decision, int $superAdminId): array
    {
        $genericId = (int) $promotion->getAttribute('generic_id');
        $generic = Generic::query()->find($genericId);

        if ($generic === null || ! $generic->is_active) {
            throw new GenericNotUsable($genericId);
        }

        $version = CatalogVersion::query()->create([
            'version' => 'promo.'.now()->format('Ymd-His').'.'.$promotion->id,
            'status' => CatalogVersionStatus::Applied,
            'applied_at' => now(),
            'applied_by' => 'super-admin:'.$superAdminId,
            'notes' => 'promotion of custom brand #'.$promotion->custom_brand_id.' (tenant '.$promotion->tenant_id.')',
            'row_counts' => [],
        ]);

        $snapshot = (array) $promotion->getAttribute('snapshot');

        if ($decision->mode === 'map') {
            $brand = Brand::query()->findOrFail((int) $decision->brandId);
            $strength = $this->findOrCreateStrength($brand, $generic, $snapshot, $decision->presentations, $version->id);
        } else {
            $brand = Brand::query()->whereRaw('lower(name) = ?', [Str::lower($promotion->brand_name)])->where('generic_id', $generic->id)->first();

            if ($brand === null) {
                $slug = Str::slug($promotion->brand_name.' '.$generic->slug);
                $i = 2;

                while (Brand::query()->where('slug', $slug)->exists()) {
                    $slug = Str::slug($promotion->brand_name.' '.$generic->slug).'-'.$i++;
                }

                $brand = Brand::query()->create([
                    'generic_id' => $generic->id, 'name' => $promotion->brand_name, 'slug' => $slug,
                    'manufacturer' => $decision->manufacturer ?? $promotion->getAttribute('manufacturer'), 'popularity' => 0, 'aliases' => [],
                    'catalog_version_id' => $version->id, 'is_active' => true,
                ]);
            }

            $strength = $this->findOrCreateStrength($brand, $generic, $snapshot, $decision->presentations, $version->id);
        }

        $version->forceFill(['row_counts' => ['brands' => ['rows' => 1, 'inserted' => $brand->wasRecentlyCreated ? 1 : 0, 'updated' => 0, 'deactivated' => 0]]])->save();

        return ['brand_id' => $brand->id, 'strength_id' => $strength?->id, 'catalog_version_id' => $version->id];
    }

    /** @param  array<string, mixed>  $snapshot */
    private function findOrCreateStrength(Brand $brand, Generic $generic, array $snapshot, ?string $presentations, int $versionId): ?Strength
    {
        $specs = $presentations !== null ? SeedRowMapper::presentations($presentations) : [];

        if ($specs === [] && ($snapshot['strength'] ?? null) !== null) {
            $formCode = $snapshot['dosage_form_id'] !== null ? DosageForm::query()->find($snapshot['dosage_form_id'])?->code->value : $this->formMapper->code($snapshot['form'] ?? null);
            $specs = [[$formCode ?? 'tab', (string) $snapshot['strength'], null]];
        }

        $first = null;

        foreach ($specs as [$formCode, $label, $pack]) {
            $form = DosageForm::query()->where('code', $this->formMapper->code($formCode) ?? $formCode)->first();
            $parsed = $this->parser->tryParse($label);

            if ($form === null || $parsed === null) {
                continue;
            }

            [$packValue, $packUnit] = $this->parser->parsePack($pack, $form->default_unit);

            $strength = Strength::query()->firstOrCreate(
                ['brand_id' => $brand->id, 'dosage_form_id' => $form->id, 'strength_label' => $parsed->label],
                [
                    'generic_id' => $generic->id, 'route_id' => $form->default_route_id, 'strength_value' => $parsed->amountValue,
                    'strength_unit' => $parsed->strengthUnit(), 'per_volume_ml' => $parsed->perVolumeMl(), 'pack_size' => $pack,
                    'strength_mg' => $parsed->strengthMg, 'per_ml' => $parsed->perMl, 'pack_size_value' => $packValue, 'pack_unit' => $packUnit,
                    'catalog_version_id' => $versionId, 'is_active' => true,
                ],
            );

            $first ??= $strength;
        }

        return $first;
    }

    /** @param  array{brand_id: int, strength_id: int|null, catalog_version_id: int}  $master */
    private function applyToTenant(CustomBrandPromotion $promotion, array $master, string $note): void
    {
        $tenant = Tenant::query()->find($promotion->tenant_id);

        if ($tenant === null) {
            return;
        }

        Tenancy::run($tenant, function () use ($promotion, $master, $note): void {
            $brand = CustomBrand::query()->find($promotion->custom_brand_id);

            if ($brand === null) {
                return;
            }

            $brand->forceFill([
                'review_status' => CustomBrandReviewStatus::Promoted, 'promoted_to_master' => true,
                'master_brand_id' => $master['brand_id'], 'master_strength_id' => $master['strength_id'],
                'reviewed_at' => now(), 'review_note' => $note,
            ])->save();                                                     // Scout observer re-indexes with the master ids
        });
    }
}
