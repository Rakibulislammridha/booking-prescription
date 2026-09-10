<?php

declare(strict_types=1);

namespace App\Http\Controllers\Super\Tenants;

use App\Domain\Tenancy\Rules\NotReservedSlug;
use App\Http\Controllers\Controller;
use App\Models\Central\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The create form's live availability check — the same three questions `ProvisionTenant` asks, answered before
 * the operator presses Create: well-formed, not reserved, not claimed (soft-deleted rows included, since a slug
 * is a hostname). JSON, because it is called on every keystroke; nothing is written.
 */
final class SlugCheckController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $slug = mb_strtolower(trim((string) $request->query('slug', '')));
        $ignore = (string) $request->query('ignore', '');
        $central = (string) config('tenancy.central_domain');

        $reason = match (true) {
            ! NotReservedSlug::isWellFormed($slug) => 'invalid',
            NotReservedSlug::isReserved($slug) => 'reserved',
            Tenant::withTrashed()->where('slug', $slug)->when($ignore !== '', fn ($q) => $q->where('public_id', '!=', $ignore))->exists() => 'taken',
            default => null,
        };

        return response()->json([
            'slug' => $slug,
            'host' => $slug === '' ? null : $slug.'.'.$central,
            'available' => $reason === null,
            'reason' => $reason,
        ]);
    }
}
