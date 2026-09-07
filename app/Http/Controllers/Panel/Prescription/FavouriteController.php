<?php

declare(strict_types=1);

namespace App\Http\Controllers\Panel\Prescription;

use App\Domain\Prescription\Actions\PinFavourite;
use App\Domain\Prescription\Actions\RemoveFavourite;
use App\Domain\Prescription\Actions\UpdateFavourite;
use App\Domain\Prescription\Services\DoctorLearningCache;
use App\Domain\Shared\Actor;
use App\Http\Controllers\Controller;
use App\Http\Requests\Panel\Prescription\PinFavouriteRequest;
use App\Http\Requests\Panel\Prescription\UpdateFavouriteRequest;
use App\Models\Tenant\DoctorFavourite;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** GET /panel/doctors/me/favourites?icd · GET …/top-drugs · POST · PATCH/DELETE …/{favourite} (PRESCRIPTION.md §3.5, §3.7). */
final class FavouriteController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $doctorId = (int) ($request->user('web')->doctor()->value('id') ?? 0);
        $icd = $request->filled('icd') ? strtoupper((string) $request->query('icd')) : null;
        $rows = DoctorFavourite::query()->where('doctor_id', $doctorId)
            ->when($icd !== null, fn ($b) => $b->where('icd10_code', $icd), fn ($b) => $b->whereNull('icd10_code'))
            ->orderByDesc('is_pinned')->orderBy('rank')->orderByDesc('use_count')->limit($icd !== null ? 15 : 50)->get();

        return response()->json(['data' => $rows->map(fn (DoctorFavourite $f) => DoctorLearningCache::favouriteRow($f))->values()->all()])->header('Cache-Control', 'private, max-age=30');
    }

    public function topDrugs(Request $request, DoctorLearningCache $cache): JsonResponse
    {
        $doctorId = (int) ($request->user('web')->doctor()->value('id') ?? 0);

        return response()->json(['data' => $cache->top50($doctorId)])->header('Cache-Control', 'private, max-age=30');
    }

    public function store(PinFavouriteRequest $request, PinFavourite $pin): JsonResponse
    {
        return response()->json(['data' => DoctorLearningCache::favouriteRow($pin->handle($request->user('web')->doctor, $request->validated(), Actor::fromRequest($request)))], 201);
    }

    public function update(UpdateFavouriteRequest $request, DoctorFavourite $favourite, UpdateFavourite $update): JsonResponse
    {
        return response()->json(['data' => DoctorLearningCache::favouriteRow($update->handle($favourite, $request->validated(), Actor::fromRequest($request)))]);
    }

    public function destroy(Request $request, DoctorFavourite $favourite, RemoveFavourite $remove): JsonResponse
    {
        abort_unless((int) ($request->user('web')->doctor()->value('id') ?? 0) === $favourite->doctor_id, 403);
        $remove->handle($favourite, Actor::fromRequest($request));

        return response()->json(['deleted' => true]);
    }
}
