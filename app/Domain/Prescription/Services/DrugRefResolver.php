<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Services;

use App\Domain\Catalog\Enums\CustomBrandReviewStatus;
use App\Domain\Catalog\Import\StrengthLabelParser;
use App\Domain\Catalog\Services\CatalogCache;
use App\Domain\Prescription\Data\DrugRef;
use App\Domain\Prescription\Shorthand\Keywords;
use App\Models\Tenant\CustomBrand;

/**
 * Turns the writer's drug reference `{generic_id, brand_id, custom_brand_id, strength_id}` into a DrugRef with
 * snapshot text + presentation facts, reading the catalog through CatalogCache (version-keyed Redis) and the
 * tenant's custom_brands row. This is the "snapshot on write" source at draft save AND again at issue (§4.6).
 */
final class DrugRefResolver
{
    public function __construct(private readonly CatalogCache $catalog, private readonly StrengthLabelParser $strengths) {}

    /** @param  array<string, mixed>  $ref */
    public function resolve(array $ref): DrugRef
    {
        $customBrandId = isset($ref['custom_brand_id']) ? (int) $ref['custom_brand_id'] : null;

        if ($customBrandId !== null && $customBrandId > 0) {
            return $this->custom($customBrandId, isset($ref['generic_id']) ? (int) $ref['generic_id'] : null);
        }

        $strengthId = isset($ref['strength_id']) ? (int) $ref['strength_id'] : null;
        $brandId = isset($ref['brand_id']) ? (int) $ref['brand_id'] : null;
        $genericId = isset($ref['generic_id']) ? (int) $ref['generic_id'] : null;

        if ($strengthId !== null && $strengthId > 0) {
            return $this->presentation($strengthId, $brandId, $genericId);
        }

        return $this->generic($genericId, $brandId);
    }

    private function presentation(int $strengthId, ?int $brandId, ?int $genericId): DrugRef
    {
        $strength = $this->catalog->strength($strengthId);

        if ($strength === null) {
            return $this->unresolved('presentation', $genericId, $brandId, null, $strengthId, 'strength');
        }

        $brandId ??= (int) $strength['brand_id'];
        $genericId ??= (int) $strength['generic_id'];
        $brand = $this->catalog->brand($brandId);
        $generic = $this->catalog->generic($genericId);

        if ($brand === null || (int) $brand['generic_id'] !== $genericId) {
            return $this->unresolved('presentation', $genericId, $brandId, null, $strengthId, 'brand');
        }

        if ($generic === null) {
            return $this->unresolved('presentation', $genericId, $brandId, null, $strengthId, 'generic');
        }

        $form = isset($strength['dosage_form_id']) ? $this->catalog->dosageForm((int) $strength['dosage_form_id']) : null;
        $routeId = isset($strength['route_id']) ? (int) $strength['route_id'] : (isset($form['default_route_id']) ? (int) $form['default_route_id'] : null);
        $route = $routeId !== null ? $this->catalog->route($routeId) : null;
        $formCode = isset($form['code']) ? (string) $form['code'] : null;
        [$packValue, $packUnitParsed] = $this->strengths->parsePack(isset($strength['pack_size']) ? (string) $strength['pack_size'] : null, $formCode);
        $packSize = isset($strength['pack_size_value']) ? (float) $strength['pack_size_value'] : ($packValue === null ? null : (float) $packValue);
        $packUnit = isset($strength['pack_unit']) ? (string) $strength['pack_unit'] : ($packUnitParsed !== null ? (string) $packUnitParsed : (Keywords::forms()[$formCode ?? '']['pack_unit'] ?? null));

        return new DrugRef(
            kind: 'presentation', genericId: $genericId, brandId: $brandId, customBrandId: null, strengthId: $strengthId,
            genericName: (string) $generic['name'], brandName: (string) $brand['name'], strength: (string) $strength['strength_label'],
            form: isset($form['name']) ? (string) $form['name'] : null, formCode: $formCode,
            route: isset($route['name']) ? (string) $route['name'] : null, routeCode: isset($route['code']) ? (string) $route['code'] : null,
            routeId: $routeId, dosageFormId: isset($strength['dosage_form_id']) ? (int) $strength['dosage_form_id'] : null,
            packSize: $packSize, packUnit: $packUnit,
            strengthMg: isset($strength['strength_mg']) ? (float) $strength['strength_mg'] : null,
            perMl: isset($strength['per_ml']) ? (float) $strength['per_ml'] : null,
            isLiquid: (bool) ($form['is_liquid'] ?? false),
            infoSlug: $this->infoSlug($genericId), therapeuticClass: $generic['therapeutic_class'] ?? null,
            isSystemic: (bool) ($route['is_systemic'] ?? true), genericActive: (bool) $generic['is_active'],
            resolved: true, manufacturer: $brand['manufacturer'] ?? null,
            defaultUnit: (string) ($form['default_unit'] ?? Keywords::forms()[$formCode ?? '']['default_unit'] ?? 'tab'),
        );
    }

    private function generic(?int $genericId, ?int $brandId): DrugRef
    {
        $generic = $genericId !== null ? $this->catalog->generic($genericId) : null;

        if ($generic === null) {
            return $this->unresolved('generic', $genericId, $brandId, null, null, 'generic');
        }

        $brand = $brandId !== null ? $this->catalog->brand($brandId) : null;

        if ($brandId !== null && ($brand === null || (int) $brand['generic_id'] !== $genericId)) {
            return $this->unresolved('generic', $genericId, $brandId, null, null, 'brand');
        }

        return new DrugRef(
            kind: 'generic', genericId: $genericId, brandId: $brandId, customBrandId: null, strengthId: null,
            genericName: (string) $generic['name'], brandName: $brand !== null ? (string) $brand['name'] : null, strength: null, form: null, formCode: null,
            route: null, routeCode: null, infoSlug: $this->infoSlug($genericId), therapeuticClass: $generic['therapeutic_class'] ?? null,
            genericActive: (bool) $generic['is_active'], resolved: true, manufacturer: $brand['manufacturer'] ?? null,
        );
    }

    private function custom(int $customBrandId, ?int $genericId): DrugRef
    {
        $row = CustomBrand::query()->withTrashed()->find($customBrandId);

        if ($row === null) {
            return $this->unresolved('custom', $genericId, null, $customBrandId, null, 'custom_brand');
        }

        $generic = $this->catalog->generic($row->generic_id);
        $form = $row->dosage_form_id !== null ? $this->catalog->dosageForm($row->dosage_form_id) : null;
        $route = $row->route_id !== null ? $this->catalog->route($row->route_id) : null;
        $parsed = $this->strengths->tryParse($row->strength);
        $formCode = isset($form['code']) ? (string) $form['code'] : null;
        $usable = $row->deleted_at === null && $row->is_active && $row->review_status !== CustomBrandReviewStatus::Rejected && $generic !== null && (bool) $generic['is_active'];

        return new DrugRef(
            kind: 'custom', genericId: $row->generic_id, brandId: null, customBrandId: $row->id, strengthId: null,
            genericName: $generic !== null ? (string) $generic['name'] : $row->generic_name, brandName: $row->brand_name, strength: $row->strength,
            form: $form['name'] ?? $row->form, formCode: $formCode, route: $route['name'] ?? $row->route, routeCode: isset($route['code']) ? (string) $route['code'] : null,
            routeId: $row->route_id, dosageFormId: $row->dosage_form_id, packSize: null, packUnit: Keywords::forms()[$formCode ?? '']['pack_unit'] ?? null,
            strengthMg: $parsed?->strengthMg, perMl: $parsed?->perMl, isLiquid: (bool) ($form['is_liquid'] ?? false),
            infoSlug: $this->infoSlug($row->generic_id), therapeuticClass: $generic['therapeutic_class'] ?? null,
            isSystemic: (bool) ($route['is_systemic'] ?? true), genericActive: $generic !== null && (bool) $generic['is_active'],
            resolved: $usable, unresolved: $usable ? null : 'custom_brand', manufacturer: $row->manufacturer,
            defaultUnit: (string) ($form['default_unit'] ?? 'tab'),
        );
    }

    private function unresolved(string $kind, ?int $genericId, ?int $brandId, ?int $customBrandId, ?int $strengthId, string $what): DrugRef
    {
        $generic = $genericId !== null ? $this->catalog->generic($genericId) : null;

        return new DrugRef(
            kind: $kind, genericId: $genericId, brandId: $brandId, customBrandId: $customBrandId, strengthId: $strengthId,
            genericName: $generic !== null ? (string) $generic['name'] : '', brandName: null, strength: null, form: null, formCode: null, route: null, routeCode: null,
            genericActive: $generic !== null && (bool) $generic['is_active'], resolved: false, unresolved: $what,
        );
    }

    private function infoSlug(int $genericId): ?string
    {
        $info = $this->catalog->drugInformationForGeneric($genericId);

        return isset($info['public_slug']) ? (string) $info['public_slug'] : null;
    }

    /**
     * The custom-brand facts CustomBrandLinkCheck needs, loaded once (pure w.r.t. tenant data inside the check).
     *
     * @return array<string, mixed>|null
     */
    public function customBrandFacts(?int $customBrandId): ?array
    {
        if ($customBrandId === null) {
            return null;
        }

        $row = CustomBrand::query()->withTrashed()->find($customBrandId);

        if ($row === null) {
            return ['id' => $customBrandId, 'exists' => false];
        }

        return ['id' => $row->id, 'exists' => true, 'deleted' => $row->deleted_at !== null, 'is_active' => $row->is_active,
            'review_status' => $row->review_status->value, 'generic_id' => $row->generic_id];
    }
}
