<?php

declare(strict_types=1);

namespace App\Http\Controllers\Super\Catalog;

use App\Domain\Catalog\Actions\PromoteCustomBrand;
use App\Domain\Catalog\Actions\RejectCustomBrandPromotion;
use App\Domain\Catalog\Enums\CustomBrandPromotionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Super\Catalog\ApprovePromotionRequest;
use App\Http\Requests\Super\Catalog\RejectPromotionRequest;
use App\Models\Central\CustomBrandPromotion;
use App\Models\Central\Tenant;
use App\Tenancy\Facades\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Super-admin review queue for custom-brand promotions (CATALOG.md §8). JSON endpoints; the SaaS module's super
 * pages consume them. The queue is public.custom_brand_promotions, so no tenant schema is scanned to list it.
 */
final class PromotionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate(['status' => ['sometimes', Rule::in(CustomBrandPromotionStatus::values())], 'q' => ['nullable', 'string', 'max:120']]);
        $status = (string) ($validated['status'] ?? CustomBrandPromotionStatus::Pending->value);

        $rows = CustomBrandPromotion::query()->with('tenant:id,slug,name')->where('status', $status)
            ->when(($validated['q'] ?? '') !== '', fn ($q) => $q->whereRaw('lower(brand_name) LIKE ?', ['%'.mb_strtolower((string) $validated['q']).'%']))
            ->orderBy('submitted_at')->paginate(50)->withQueryString();

        return response()->json($rows->through(fn (CustomBrandPromotion $p) => self::present($p)));
    }

    public function show(CustomBrandPromotion $promotion): JsonResponse
    {
        $similar = DB::connection('catalog')->table('brands AS b')->join('generics AS g', 'g.id', '=', 'b.generic_id')
            ->whereRaw('similarity(b.name, ?) > 0.3', [$promotion->brand_name])
            ->orderByRaw('similarity(b.name, ?) DESC', [$promotion->brand_name])->limit(10)
            ->get(['b.id', 'b.name', 'b.manufacturer', 'b.generic_id', 'g.name AS generic_name', 'b.is_active']);

        $snapshot = (array) $promotion->getAttribute('snapshot');
        $generic = (int) $promotion->getAttribute('generic_id');
        $useCount = (int) ($snapshot['use_count'] ?? 0);
        $tenant = Tenant::query()->find($promotion->tenant_id);

        if ($tenant !== null && $promotion->status === CustomBrandPromotionStatus::Pending->value) {
            $useCount = (int) Tenancy::run($tenant, fn () => DB::table('custom_brands')->where('id', $promotion->custom_brand_id)->value('use_count') ?? $useCount);
        }

        return response()->json(self::present($promotion) + [
            'use_count' => $useCount,
            'similar_master_brands' => $similar->map(fn ($b) => ['id' => (int) $b->id, 'name' => $b->name, 'manufacturer' => $b->manufacturer, 'generic_id' => (int) $b->generic_id,
                'generic_name' => $b->generic_name, 'is_active' => (bool) $b->is_active, 'same_generic' => (int) $b->generic_id === $generic])->values(),
        ]);
    }

    public function approve(ApprovePromotionRequest $request, CustomBrandPromotion $promotion, PromoteCustomBrand $action): JsonResponse
    {
        $master = $action->handle($promotion, $request->toData(), (int) $request->user('super')?->getAuthIdentifier());

        return response()->json(['promotion' => self::present($promotion->refresh()), 'master' => $master]);
    }

    public function reject(RejectPromotionRequest $request, CustomBrandPromotion $promotion, RejectCustomBrandPromotion $action): JsonResponse
    {
        $action->handle($promotion, (string) $request->validated('reason'), (int) $request->user('super')?->getAuthIdentifier());

        return response()->json(['promotion' => self::present($promotion->refresh())]);
    }

    /** @return array<string, mixed> */
    private static function present(CustomBrandPromotion $p): array
    {
        $submitted = $p->getAttribute('submitted_at');
        $reviewed = $p->getAttribute('reviewed_at');

        return [
            'public_id' => $p->public_id, 'tenant' => $p->relationLoaded('tenant') && $p->tenant !== null ? ['id' => $p->tenant->id, 'slug' => $p->tenant->slug, 'name' => $p->tenant->name] : ['id' => $p->tenant_id],
            'custom_brand_id' => $p->custom_brand_id, 'brand_name' => $p->brand_name, 'manufacturer' => $p->getAttribute('manufacturer'),
            'generic_id' => (int) $p->getAttribute('generic_id'), 'generic_name' => $p->getAttribute('generic_name'), 'snapshot' => $p->getAttribute('snapshot'), 'status' => $p->status,
            'submitted_at' => $submitted instanceof \DateTimeInterface ? CarbonImmutable::instance($submitted)->toIso8601String() : null,
            'reviewed_at' => $reviewed instanceof \DateTimeInterface ? CarbonImmutable::instance($reviewed)->toIso8601String() : null,
            'decision' => $p->getAttribute('decision'),
        ];
    }
}
