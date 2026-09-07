<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Catalog;

use App\Domain\Catalog\Search\Icd10SearchService;
use App\Http\Controllers\Controller;
use App\Models\Tenant\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** GET /api/catalog/icd10?q=sugar&limit=10 (PRESCRIPTION.md §3.4). */
final class Icd10SearchController extends Controller
{
    public function __invoke(Request $request, Icd10SearchService $search): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'max:120'],
            'limit' => ['sometimes', 'integer', 'between:1,40'],
            'include_categories' => ['sometimes', 'boolean'],
        ]);

        /** @var User|null $user */
        $user = $request->user('web');
        $doctorId = $user?->doctor?->id;

        $result = $search->search(
            (string) $validated['q'],
            (int) ($validated['limit'] ?? 10),
            $doctorId !== null ? (int) $doctorId : null,
            ! (bool) ($validated['include_categories'] ?? false),
        );

        return response()->json($result)->header('Cache-Control', 'private, max-age=30');
    }
}
