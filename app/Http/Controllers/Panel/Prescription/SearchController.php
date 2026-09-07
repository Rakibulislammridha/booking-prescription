<?php

declare(strict_types=1);

namespace App\Http\Controllers\Panel\Prescription;

use App\Domain\Catalog\Data\DrugSearchQuery;
use App\Domain\Catalog\Search\DrugSearchService;
use App\Domain\Catalog\Search\Icd10SearchService;
use App\Http\Controllers\Controller;
use App\Models\Tenant\Doctor;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The writer's autocomplete reads under /panel/search/* (PRESCRIPTION.md §3.8; the documented JSON exception of
 * CONVENTIONS §5) — thin delegates to the Catalog module's DrugSearchService / Icd10SearchService (which apply this
 * module's DoctorUsageBoost), plus the tenant doctor search for referrals (§4.9).
 */
final class SearchController extends Controller
{
    public function drugs(Request $request, DrugSearchService $search): JsonResponse
    {
        $v = $request->validate(['q' => ['required', 'string', 'max:120'], 'dx' => ['sometimes', 'array', 'max:10'], 'dx.*' => ['string', 'max:8'], 'limit' => ['sometimes', 'integer', 'between:1,40'], 'strength_mg' => ['sometimes', 'numeric', 'min:0']]);
        $doctorId = $request->user('web')?->doctor()->value('id');
        $result = $search->search(new DrugSearchQuery((string) $v['q'], $doctorId !== null ? (int) $doctorId : null, array_values((array) ($v['dx'] ?? [])), isset($v['strength_mg']) ? (float) $v['strength_mg'] : null, (int) ($v['limit'] ?? 12)));

        return response()->json($result)->header('Cache-Control', 'private, max-age=30');
    }

    public function icd(Request $request, Icd10SearchService $search): JsonResponse
    {
        $v = $request->validate(['q' => ['required', 'string', 'max:120'], 'limit' => ['sometimes', 'integer', 'between:1,40']]);
        $doctorId = $request->user('web')?->doctor()->value('id');

        return response()->json($search->search((string) $v['q'], (int) ($v['limit'] ?? 10), $doctorId !== null ? (int) $doctorId : null))->header('Cache-Control', 'private, max-age=30');
    }

    public function doctors(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        $rows = Doctor::query()->active()->with('specialties:id,name')
            ->when($q !== '', fn ($b) => $b->where(fn ($w) => $w->where('name', 'ILIKE', "%{$q}%")->orWhere('name_bn', 'ILIKE', "%{$q}%")))
            ->orderBy('sort_order')->orderBy('name')->limit(20)->get();

        return response()->json(['hits' => $rows->map(fn (Doctor $d) => ['id' => $d->id, 'public_id' => $d->public_id, 'name' => $d->name, 'name_bn' => $d->name_bn, 'specialty' => $d->specialties->first()?->name])->values()->all()])->header('Cache-Control', 'private, max-age=30');
    }
}
