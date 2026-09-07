<?php

declare(strict_types=1);

namespace App\Http\Controllers\Panel\Prescription;

use App\Domain\Prescription\Actions\CreateDraftPrescription;
use App\Domain\Prescription\Actions\DeleteDraft;
use App\Domain\Prescription\Actions\SaveDraft;
use App\Domain\Prescription\Services\DraftSerializer;
use App\Domain\Shared\Actor;
use App\Http\Controllers\Controller;
use App\Http\Requests\Panel\Prescription\SaveDraftRequest;
use App\Models\Tenant\Prescription;
use App\Models\Tenant\Visit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** POST /panel/visits/{visit}/prescriptions (create/get the draft) · PATCH …/draft (§4.13) · DELETE (abandon). */
final class DraftController extends Controller
{
    public function store(Request $request, Visit $visit, CreateDraftPrescription $create, DraftSerializer $serializer): JsonResponse
    {
        $this->authorize('write', $visit);
        $draft = $create->handle($visit, $request->user('web')->doctor, Actor::fromRequest($request));

        return response()->json(['prescription' => $serializer->draft($draft)], $draft->wasRecentlyCreated ? 201 : 200);
    }

    public function update(SaveDraftRequest $request, Prescription $prescription, SaveDraft $save): JsonResponse
    {
        return response()->json($save->handle($prescription, $request->toData(), Actor::fromRequest($request))->toArray());
    }

    public function destroy(Request $request, Prescription $prescription, DeleteDraft $delete): JsonResponse
    {
        $this->authorize('delete', $prescription);
        $delete->handle($prescription, Actor::fromRequest($request));

        return response()->json(['deleted' => true]);
    }
}
