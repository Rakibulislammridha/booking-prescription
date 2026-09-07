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
use App\Models\Tenant\User;
use App\Tenancy\Facades\Tenancy;
use Illuminate\Support\Facades\DB;

/**
 * POST /panel/custom-brands (CATALOG.md §8): snapshots generic/form/route names from the catalog, creates the row as
 * pending (usable immediately — it has a generic) and raises CustomBrandCreated after commit.
 */
final class CreateCustomBrand
{
    public function __construct(private readonly CatalogCache $catalog, private readonly StrengthLabelParser $parser) {}

    public function handle(CustomBrandData $data, Actor $actor): CustomBrand
    {
        $generic = $this->catalog->generic($data->genericId);

        if ($generic === null || ! $generic['is_active']) {
            throw new GenericNotUsable($data->genericId);
        }

        $form = $data->dosageFormId !== null ? $this->catalog->dosageForm($data->dosageFormId) : null;
        $route = $data->routeId !== null ? $this->catalog->route($data->routeId) : ($form !== null && $form['default_route_id'] !== null ? $this->catalog->route((int) $form['default_route_id']) : null);
        $strength = $this->parser->tryParse($data->strength)->label ?? $data->strength;

        return DB::transaction(function () use ($data, $actor, $generic, $form, $route, $strength): CustomBrand {
            $brand = CustomBrand::query()->create([
                'generic_id' => (int) $generic['id'],
                'generic_name' => (string) $generic['name'],
                'brand_name' => $data->brandName,
                'manufacturer' => $data->manufacturer,
                'strength' => $strength,
                'dosage_form_id' => $form['id'] ?? null,
                'form' => $form['name'] ?? null,
                'route_id' => $route['id'] ?? null,
                'route' => $route['name'] ?? null,
                'review_status' => CustomBrandReviewStatus::Pending,
                'promoted_to_master' => false,
                'created_by_user_id' => $actor->userId,
                'use_count' => 0,
                'is_active' => true,
            ]);

            $creator = $actor->userId !== null ? User::query()->find($actor->userId) : null;

            event(new CustomBrandCreated((int) Tenancy::id(), $brand->id, $brand->only([
                'brand_name', 'manufacturer', 'generic_id', 'generic_name', 'strength', 'dosage_form_id', 'form', 'route_id', 'route', 'use_count',
            ]) + ['created_by_user_public_id' => $creator?->public_id]));

            return $brand;
        });
    }
}
