<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Catalog;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * GET /api/catalog/generics?q=para — molecule picker for the custom-brand form (name / alias prefix, Postgres, no
 * index needed). Also serves the closed dosage-form / route vocabularies for the same form.
 */
final class GenericLookupController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $validated = $request->validate(['q' => ['nullable', 'string', 'max:80'], 'limit' => ['sometimes', 'integer', 'between:1,50']]);
        $needle = mb_strtolower(trim((string) ($validated['q'] ?? '')));
        $like = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $needle).'%';

        $rows = DB::connection('catalog')->table('generics')->where('is_active', true)->where('needs_review', false)
            ->when($needle !== '', fn ($q) => $q->where(function ($w) use ($like): void {
                $w->whereRaw('lower(name) LIKE ?', [$like])->orWhereRaw('EXISTS (SELECT 1 FROM jsonb_array_elements_text(aliases) a WHERE lower(a) LIKE ?)', [$like]);
            }))
            ->orderBy('name')->limit((int) ($validated['limit'] ?? 20))
            ->get(['id', 'name', 'name_bn', 'therapeutic_class', 'aliases']);

        return response()->json([
            'q' => $needle,
            'hits' => $rows->map(fn ($g) => [
                'id' => (int) $g->id, 'name' => $g->name, 'name_bn' => $g->name_bn, 'therapeutic_class' => $g->therapeutic_class,
                'aliases' => json_decode((string) $g->aliases, true) ?: [],
            ])->values(),
        ])->header('Cache-Control', 'private, max-age=30');
    }
}
