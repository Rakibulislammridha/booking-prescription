<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Catalog;

use App\Domain\Catalog\Data\DrugSearchQuery;
use App\Domain\Catalog\Search\DrugSearchService;
use App\Http\Controllers\Controller;
use App\Models\Tenant\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * GET /api/catalog/drugs?q=nap&dx[]=J06.9&limit=12 — the merged master + custom autocomplete (PRESCRIPTION.md §3.3).
 * Cacheable per user for 30 s; the doctor id (for usage boosts) comes from the session user.
 */
final class DrugSearchController extends Controller
{
    public function __invoke(Request $request, DrugSearchService $search): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'max:120'],
            'dx' => ['sometimes', 'array', 'max:10'],
            'dx.*' => ['string', 'max:8'],
            'limit' => ['sometimes', 'integer', 'between:1,40'],
            'strength_mg' => ['sometimes', 'numeric', 'min:0'],
        ]);

        /** @var User|null $user */
        $user = $request->user('web');
        $doctorId = $user?->doctor?->id;

        $result = $search->search(new DrugSearchQuery(
            q: (string) $validated['q'],
            doctorId: $doctorId !== null ? (int) $doctorId : null,
            dxCodes: array_values((array) ($validated['dx'] ?? [])),
            strengthMg: isset($validated['strength_mg']) ? (float) $validated['strength_mg'] : null,
            limit: (int) ($validated['limit'] ?? 12),
        ));

        return response()->json($result)->header('Cache-Control', 'private, max-age=30');
    }
}
