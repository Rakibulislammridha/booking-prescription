<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Actions;

use App\Domain\Catalog\Data\CustomBrandData;
use App\Domain\Catalog\Enums\CustomBrandReviewStatus;
use App\Domain\Catalog\Events\CustomBrandCreated;
use App\Domain\Catalog\Exceptions\GenericNotUsable;
use App\Domain\Catalog\Import\StrengthLabelParser;
use App\Domain\Catalog\Services\CatalogCache;
use App\Domain\Shared\Actor;
use App\Models\Tenant\CustomBrand;
use App\Tenancy\Facades\Tenancy;
use Illuminate\Support\Facades\DB;

/**
 * Edits a not-yet-promoted custom brand; a rejected one goes back to pending (resubmission) so the queue row reopens.
 * Promoted brands are frozen — the master row is the truth now.
 */
final class UpdateCustomBrand
{
    public function __construct(private readonly CatalogCache $catalog, private readonly StrengthLabelParser $parser) {}

    public function handle(CustomBrand $brand, CustomBrandData $data, Actor $actor): CustomBrand
    {
        if ($brand->review_status === CustomBrandReviewStatus::Promoted) {
            return $brand;
        }

        $generic = $this->catalog->generic($data->genericId);

        if ($generic === null || ! $generic['is_active']) {
            throw new GenericNotUsable($data->genericId);
        }

        $form = $data->dosageFormId !== null ? $this->catalog->dosageForm($data->dosageFormId) : null;
        $route = $data->routeId !== null ? $this->catalog->route($data->routeId) : ($form !== null && $form['default_route_id'] !== null ? $this->catalog->route((int) $form['default_route_id']) : null);
        $strength = $this->parser->tryParse($data->strength)->label ?? $data->strength;

        return DB::transaction(function () use ($brand, $data, $generic, $form, $route, $strength): CustomBrand {
            $resubmitted = $brand->review_status === CustomBrandReviewStatus::Rejected;

            $brand->fill([
                'generic_id' => (int) $generic['id'], 'generic_name' => (string) $generic['name'], 'brand_name' => $data->brandName,
                'manufacturer' => $data->manufacturer, 'strength' => $strength, 'dosage_form_id' => $form['id'] ?? null, 'form' => $form['name'] ?? null,
                'route_id' => $route['id'] ?? null, 'route' => $route['name'] ?? null, 'is_active' => true,
            ]);

            if ($resubmitted) {
                $brand->fill(['review_status' => CustomBrandReviewStatus::Pending, 'review_note' => null, 'reviewed_at' => null]);
            }

            $brand->save();

            if ($resubmitted) {
                event(new CustomBrandCreated((int) Tenancy::id(), $brand->id, $brand->only([
                    'brand_name', 'manufacturer', 'generic_id', 'generic_name', 'strength', 'dosage_form_id', 'form', 'route_id', 'route', 'use_count',
                ])));
            }

            return $brand;
        });
    }
}
