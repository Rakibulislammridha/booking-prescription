<?php

declare(strict_types=1);

namespace App\Http\Controllers\Panel\Prescription;

use App\Domain\Prescription\Actions\RecordVitals;
use App\Domain\Prescription\Actions\UpdateVitals;
use App\Domain\Prescription\Services\DraftSerializer;
use App\Domain\Shared\Actor;
use App\Http\Controllers\Controller;
use App\Http\Requests\Panel\Prescription\RecordVitalsRequest;
use App\Models\Tenant\AuditLog;
use App\Models\Tenant\Visit;
use App\Models\Tenant\Vital;
use Illuminate\Http\JsonResponse;

/** POST /panel/visits/{visit}/vitals (compounder) · PATCH /panel/vitals/{vital} (doctor edit / review) · GET list. */
final class VitalsController extends Controller
{
    public function index(Visit $visit, DraftSerializer $serializer): JsonResponse
    {
        $this->authorize('view', $visit);
        AuditLog::view($visit, ['section' => 'vitals']);

        return response()->json(['data' => $visit->vitals()->with('recordedBy')->orderByDesc('recorded_at')->get()->map(fn (Vital $v) => $serializer->vitals($v))->values()->all()]);
    }

    public function store(RecordVitalsRequest $request, Visit $visit, RecordVitals $record, DraftSerializer $serializer): JsonResponse
    {
        $user = $request->user('web');
        $byDoctor = $user->doctor()->value('id') !== null && (int) $user->doctor()->value('id') === $visit->doctor_id;
        $vital = $record->handle($visit, $request->toData(), Actor::fromRequest($request), $byDoctor);

        return response()->json(['vitals' => $serializer->vitals($vital->load('recordedBy'))], 201);
    }

    public function update(RecordVitalsRequest $request, Vital $vital, UpdateVitals $update, DraftSerializer $serializer): JsonResponse
    {
        $user = $request->user('web');
        $byDoctor = $user->doctor()->value('id') !== null && (int) $user->doctor()->value('id') === $vital->visit->doctor_id;
        $vital = $update->handle($vital, $request->toData(), Actor::fromRequest($request), $byDoctor || $user->can('prescriptions.view.any'));

        return response()->json(['vitals' => $serializer->vitals($vital->load('recordedBy'))]);
    }
}
