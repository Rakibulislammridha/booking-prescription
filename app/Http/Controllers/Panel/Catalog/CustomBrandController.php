<?php

declare(strict_types=1);

namespace App\Http\Controllers\Panel\Catalog;

use App\Domain\Catalog\Actions\CreateCustomBrand;
use App\Domain\Catalog\Actions\DeleteCustomBrand;
use App\Domain\Catalog\Actions\UpdateCustomBrand;
use App\Domain\Shared\Actor;
use App\Http\Controllers\Controller;
use App\Http\Requests\Panel\Catalog\StoreCustomBrandRequest;
use App\Http\Requests\Panel\Catalog\UpdateCustomBrandRequest;
use App\Models\Tenant\CustomBrand;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Tenant custom brands (CATALOG.md §8): Inertia page Catalog/CustomBrands with a simple table + form. Creating a row
 * is the promotion submission. Rows are bigint ids in panel URLs only (CONVENTIONS §5).
 */
final class CustomBrandController extends Controller
{
    use AuthorizesRequests;

    public function index(Request $request): Response
    {
        $this->authorize('viewAny', CustomBrand::class);

        $brands = CustomBrand::query()->orderByDesc('id')->paginate(50)->withQueryString();

        return Inertia::render('Catalog/CustomBrands', [
            'brands' => $brands->through(fn (CustomBrand $b) => self::present($b)),
            'forms' => DB::connection('catalog')->table('dosage_forms')->where('is_active', true)->orderBy('id')->get(['id', 'code', 'name', 'name_bn'])
                ->map(fn ($f) => ['id' => (int) $f->id, 'code' => $f->code, 'name' => $f->name, 'name_bn' => $f->name_bn])->values(),
            'routes' => DB::connection('catalog')->table('routes')->where('is_active', true)->orderBy('id')->get(['id', 'code', 'name', 'name_bn'])
                ->map(fn ($r) => ['id' => (int) $r->id, 'code' => $r->code, 'name' => $r->name, 'name_bn' => $r->name_bn])->values(),
            'can' => ['create' => $request->user('web')?->can('create', CustomBrand::class) ?? false, 'delete' => $request->user('web')?->can('delete', new CustomBrand) ?? false],
        ]);
    }

    public function store(StoreCustomBrandRequest $request, CreateCustomBrand $action): RedirectResponse
    {
        $brand = $action->handle($request->toData(), Actor::fromRequest($request));

        return redirect()->route('panel.catalog.custom-brands.index')->with('success', __('catalog.custom_brands.flash.created', ['name' => $brand->brand_name]));
    }

    public function update(UpdateCustomBrandRequest $request, CustomBrand $customBrand, UpdateCustomBrand $action): RedirectResponse
    {
        $action->handle($customBrand, $request->toData(), Actor::fromRequest($request));

        return redirect()->route('panel.catalog.custom-brands.index')->with('success', __('catalog.custom_brands.flash.updated', ['name' => $customBrand->brand_name]));
    }

    public function destroy(Request $request, CustomBrand $customBrand, DeleteCustomBrand $action): RedirectResponse
    {
        $this->authorize('delete', $customBrand);
        $action->handle($customBrand, Actor::fromRequest($request));

        return redirect()->route('panel.catalog.custom-brands.index')->with('success', __('catalog.custom_brands.flash.deleted', ['name' => $customBrand->brand_name]));
    }

    /** @return array<string, mixed> */
    public static function present(CustomBrand $b): array
    {
        return [
            'id' => $b->id, 'brand_name' => $b->brand_name, 'generic_id' => $b->generic_id, 'generic_name' => $b->generic_name,
            'manufacturer' => $b->manufacturer, 'strength' => $b->strength, 'dosage_form_id' => $b->dosage_form_id, 'form' => $b->form,
            'route_id' => $b->route_id, 'route' => $b->route, 'review_status' => $b->review_status->value, 'promoted_to_master' => $b->promoted_to_master,
            'master_brand_id' => $b->master_brand_id, 'master_strength_id' => $b->master_strength_id, 'review_note' => $b->review_note,
            'reviewed_at' => $b->reviewed_at?->toIso8601String(), 'use_count' => $b->use_count, 'is_active' => $b->is_active,
            'created_at' => $b->created_at?->toIso8601String(),
        ];
    }
}
