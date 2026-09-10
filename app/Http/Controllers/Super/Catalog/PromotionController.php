<?php

declare(strict_types=1);

namespace App\Http\Controllers\Super\Catalog;

use App\Domain\Audit\Enums\CentralAuditAction;
use App\Domain\Catalog\Actions\PromoteCustomBrand;
use App\Domain\Catalog\Actions\RejectCustomBrandPromotion;
use App\Domain\Catalog\Enums\CustomBrandPromotionStatus;
use App\Domain\Catalog\Search\CatalogSearchIndexer;
use App\Domain\SaaS\Services\CentralAudit;
use App\Domain\SaaS\Services\PlatformMailer;
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
use Meilisearch\Client;
use Throwable;

/**
 * Super-admin review queue for custom-brand promotions (CATALOG.md §8). JSON endpoints; the console's Review page
 * consumes them. The queue is public.custom_brand_promotions, so no tenant schema is scanned to list it.
 *
 * "Similar master brands" come from the same Meilisearch index doctors type into (`catalog_drugs`, brand names
 * with typo tolerance and Bangla aliases), falling back to the trigram index when the engine is not in play —
 * so an operator sees the candidates a doctor would have been offered. Every decision is a `catalog_promote`
 * row in `audit_logs_central`; a rejection also tells the clinic's owner why, by mail, in their language.
 */
final class PromotionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['sometimes', Rule::in(CustomBrandPromotionStatus::values())],
            'q' => ['nullable', 'string', 'max:120'],
            'tenant' => ['nullable', 'string', 'size:26'],
            'generic_id' => ['nullable', 'integer'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $status = (string) ($validated['status'] ?? CustomBrandPromotionStatus::Pending->value);
        $tenantId = isset($validated['tenant']) ? Tenant::query()->where('public_id', (string) $validated['tenant'])->value('id') : null;

        $rows = CustomBrandPromotion::query()->with('tenant:id,public_id,slug,name')->where('status', $status)
            ->when(($validated['q'] ?? '') !== '', fn ($q) => $q->where(fn ($w) => $w->whereRaw('lower(brand_name) LIKE ?', ['%'.mb_strtolower((string) $validated['q']).'%'])
                ->orWhereRaw('lower(generic_name) LIKE ?', ['%'.mb_strtolower((string) $validated['q']).'%'])))
            ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
            ->when(isset($validated['generic_id']), fn ($q) => $q->where('generic_id', (int) $validated['generic_id']))
            ->orderBy('submitted_at')->paginate(50)->withQueryString();

        return response()->json($rows->through(fn (CustomBrandPromotion $p) => self::present($p)));
    }

    /** The clinics that have ever submitted a brand — the queue's tenant filter. */
    public function tenants(): JsonResponse
    {
        $rows = Tenant::query()->whereIn('id', CustomBrandPromotion::query()->select('tenant_id'))->orderBy('name')->get(['public_id', 'name', 'slug']);

        return response()->json($rows->map(fn (Tenant $t) => ['public_id' => $t->public_id, 'name' => $t->name, 'slug' => $t->slug])->values());
    }

    public function show(CustomBrandPromotion $promotion): JsonResponse
    {
        $promotion->load('tenant:id,public_id,slug,name');
        $snapshot = (array) $promotion->getAttribute('snapshot');
        $generic = (int) $promotion->getAttribute('generic_id');
        $useCount = (int) ($snapshot['use_count'] ?? 0);
        $tenant = Tenant::query()->find($promotion->tenant_id);

        if ($tenant !== null && $promotion->status === CustomBrandPromotionStatus::Pending->value) {
            $useCount = (int) Tenancy::run($tenant, fn () => DB::table('custom_brands')->where('id', $promotion->custom_brand_id)->value('use_count') ?? $useCount);
        }

        $catalogGeneric = DB::connection('catalog')->table('generics')->where('id', $generic)->first(['id', 'name', 'slug', 'is_active']);

        return response()->json(self::present($promotion) + [
            'use_count' => $useCount,
            'catalog_generic' => $catalogGeneric === null ? null : ['id' => (int) $catalogGeneric->id, 'name' => $catalogGeneric->name, 'slug' => $catalogGeneric->slug, 'is_active' => (bool) $catalogGeneric->is_active],
            'proposed' => [
                'brand_name' => $promotion->brand_name, 'manufacturer' => $promotion->getAttribute('manufacturer'), 'generic_name' => $promotion->getAttribute('generic_name'),
                'strength' => $snapshot['strength'] ?? null, 'form' => $snapshot['form'] ?? null, 'route' => $snapshot['route'] ?? null,
            ],
            'similar_master_brands' => $this->similar($promotion->brand_name, $generic),
        ]);
    }

    public function approve(ApprovePromotionRequest $request, CustomBrandPromotion $promotion, PromoteCustomBrand $action, CentralAudit $audit): JsonResponse
    {
        $adminId = (int) $request->user('super')?->getAuthIdentifier();
        $decision = $request->toData();
        $master = $action->handle($promotion, $decision, $adminId);

        $audit->record(CentralAuditAction::CatalogPromote, Tenant::query()->find($promotion->tenant_id), $promotion, ['status' => 'pending'], [
            'status' => 'promoted', 'mode' => $decision->mode, 'brand_name' => $promotion->brand_name, 'brand_id' => $master['brand_id'],
            'strength_id' => $master['strength_id'], 'catalog_version_id' => $master['catalog_version_id'], 'note' => $decision->note,
        ], $adminId);

        return response()->json(['promotion' => self::present($promotion->refresh()->load('tenant:id,public_id,slug,name')), 'master' => $master]);
    }

    public function reject(RejectPromotionRequest $request, CustomBrandPromotion $promotion, RejectCustomBrandPromotion $action, CentralAudit $audit, PlatformMailer $mailer): JsonResponse
    {
        $adminId = (int) $request->user('super')?->getAuthIdentifier();
        $reason = (string) $request->validated('reason');
        $action->handle($promotion, $reason, $adminId);

        $tenant = Tenant::query()->find($promotion->tenant_id);
        $audit->record(CentralAuditAction::CatalogPromote, $tenant, $promotion, ['status' => 'pending'], ['status' => 'rejected', 'brand_name' => $promotion->brand_name, 'reason' => $reason], $adminId);

        // The reason reaches the clinic twice: on the custom-brand row (their panel shows it) and by mail to the owner.
        $notified = $tenant !== null && $mailer->toOwner($tenant, 'saas.mail.promotion_rejected.subject', 'saas.mail.promotion_rejected.body', [
            'brand' => $promotion->brand_name, 'generic' => (string) $promotion->getAttribute('generic_name'), 'reason' => $reason,
        ], null, $adminId);

        return response()->json(['promotion' => self::present($promotion->refresh()->load('tenant:id,public_id,slug,name')), 'notified' => $notified]);
    }

    /**
     * Master brands whose name resembles the proposal, with their strengths so the operator can compare the
     * clinic's proposed presentation against what the catalogue already has.
     *
     * @return list<array<string, mixed>>
     */
    private function similar(string $name, int $genericId): array
    {
        $ids = $this->searchBrandIds($name);
        $query = DB::connection('catalog')->table('brands AS b')->join('generics AS g', 'g.id', '=', 'b.generic_id');

        if ($ids !== null) {
            if ($ids === []) {
                return [];
            }

            $query->whereIn('b.id', $ids)->orderByRaw('array_position(ARRAY['.implode(',', array_map('intval', $ids)).']::bigint[], b.id)');
        } else {
            $query->whereRaw('similarity(b.name, ?) > 0.3', [$name])->orderByRaw('similarity(b.name, ?) DESC', [$name]);
        }

        $brands = $query->limit(10)->get(['b.id', 'b.name', 'b.manufacturer', 'b.generic_id', 'g.name AS generic_name', 'b.is_active']);
        $strengths = DB::connection('catalog')->table('strengths AS s')->join('dosage_forms AS f', 'f.id', '=', 's.dosage_form_id')
            ->whereIn('s.brand_id', $brands->pluck('id')->all())->orderBy('s.id')->get(['s.id', 's.brand_id', 's.strength_label', 'f.name AS form', 's.pack_size', 's.is_active'])
            ->groupBy('brand_id');

        return $brands->map(fn ($b) => [
            'id' => (int) $b->id, 'name' => $b->name, 'manufacturer' => $b->manufacturer, 'generic_id' => (int) $b->generic_id, 'generic_name' => $b->generic_name,
            'is_active' => (bool) $b->is_active, 'same_generic' => (int) $b->generic_id === $genericId,
            'strengths' => ($strengths[$b->id] ?? collect())->map(fn ($s) => ['id' => (int) $s->id, 'strength_label' => $s->strength_label, 'form' => $s->form, 'pack_size' => $s->pack_size, 'is_active' => (bool) $s->is_active])->values()->all(),
        ])->values()->all();
    }

    /** Brand ids by name similarity from Meilisearch (ranked, distinct by brand); null = engine not in play → trigram. */
    /** @return list<int>|null */
    private function searchBrandIds(string $name): ?array
    {
        if (config('scout.driver') !== 'meilisearch') {
            return null;
        }

        try {
            $indexer = app(CatalogSearchIndexer::class);
            $hits = app(Client::class)->index($indexer->uid(CatalogSearchIndexer::DRUGS))->rawSearch($name, [
                'limit' => 40, 'filter' => 'doc_type = presentation', 'distinct' => 'brand_id', 'attributesToRetrieve' => ['brand_id'], 'attributesToSearchOn' => ['brand_name', 'brand_aliases'],
            ])['hits'] ?? [];
            $ids = [];

            foreach ($hits as $hit) {
                if (isset($hit['brand_id'])) {
                    $ids[(int) $hit['brand_id']] = true;
                }
            }

            return array_slice(array_keys($ids), 0, 10);
        } catch (Throwable) {
            return null;
        }
    }

    /** @return array<string, mixed> */
    private static function present(CustomBrandPromotion $p): array
    {
        $submitted = $p->getAttribute('submitted_at');
        $reviewed = $p->getAttribute('reviewed_at');

        return [
            'public_id' => $p->public_id,
            'tenant' => $p->relationLoaded('tenant') && $p->tenant !== null
                ? ['id' => $p->tenant->id, 'public_id' => $p->tenant->public_id, 'slug' => $p->tenant->slug, 'name' => $p->tenant->name]
                : ['id' => $p->tenant_id],
            'custom_brand_id' => $p->custom_brand_id, 'brand_name' => $p->brand_name, 'manufacturer' => $p->getAttribute('manufacturer'),
            'generic_id' => (int) $p->getAttribute('generic_id'), 'generic_name' => $p->getAttribute('generic_name'), 'snapshot' => $p->getAttribute('snapshot'), 'status' => $p->status,
            'submitted_at' => $submitted instanceof \DateTimeInterface ? CarbonImmutable::instance($submitted)->toIso8601String() : null,
            'reviewed_at' => $reviewed instanceof \DateTimeInterface ? CarbonImmutable::instance($reviewed)->toIso8601String() : null,
            'decision' => $p->getAttribute('decision'),
        ];
    }
}
