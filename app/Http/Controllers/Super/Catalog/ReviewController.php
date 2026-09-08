<?php

declare(strict_types=1);

namespace App\Http\Controllers\Super\Catalog;

use App\Domain\Catalog\Enums\CustomBrandPromotionStatus;
use App\Http\Controllers\Controller;
use App\Models\Central\CustomBrandPromotion;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The page shell for the custom-brand promotion queue (BRIEF §3.4, CATALOG.md §8).
 *
 * The Catalog module already owns the JSON endpoints at `super.catalog.promotions.*` — index, show with
 * similar-brand suggestions, approve and reject — and they are not re-implemented here. This renders the console
 * screen and hands the page the URLs to call, which is what "wire Catalog's existing endpoints into the console"
 * means: one queue, one decision path, one owner.
 */
final class ReviewController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $validated = $request->validate(['status' => ['nullable', Rule::in(CustomBrandPromotionStatus::values())]]);
        $status = (string) ($validated['status'] ?? CustomBrandPromotionStatus::Pending->value);

        return Inertia::render('Super/Catalog/Review', [
            'status' => $status,
            'statuses' => CustomBrandPromotionStatus::values(),
            'counts' => CustomBrandPromotion::query()->selectRaw('status, count(*) as total')->groupBy('status')
                ->pluck('total', 'status')->map(fn ($v): int => (int) $v)->all(),
            'endpoints' => [
                'index' => route('super.catalog.promotions.index'),
                'show' => route('super.catalog.promotions.show', ['promotion' => '__ID__']),
                'approve' => route('super.catalog.promotions.approve', ['promotion' => '__ID__']),
                'reject' => route('super.catalog.promotions.reject', ['promotion' => '__ID__']),
            ],
        ]);
    }
}
