<?php

declare(strict_types=1);

namespace App\Http\Controllers\Panel\Prescription;

use App\Domain\Clinic\Services\ActiveBranch;
use App\Domain\Prescription\Actions\CloseVisit;
use App\Domain\Prescription\Actions\StartAdhocVisit;
use App\Domain\Prescription\Actions\StartVisit;
use App\Domain\Prescription\Actions\UpdateVisit;
use App\Domain\Prescription\Enums\VisitType;
use App\Domain\Prescription\Services\DraftSerializer;
use App\Domain\Shared\Actor;
use App\Http\Controllers\Controller;
use App\Http\Requests\Panel\Prescription\StartAdhocVisitRequest;
use App\Http\Requests\Panel\Prescription\UpdateVisitRequest;
use App\Models\Tenant\AuditLog;
use App\Models\Tenant\Patient;
use App\Models\Tenant\Serial;
use App\Models\Tenant\Visit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Visit lifecycle JSON endpoints (names panel.prescription.visits.*). */
final class VisitController extends Controller
{
    /** POST /panel/serials/{serial}/visit — open (idempotently) the visit for a called serial. */
    public function start(Request $request, Serial $serial, StartVisit $start, DraftSerializer $serializer): JsonResponse
    {
        $this->authorize('view', $serial);
        $visit = $start->handle($serial, Actor::fromRequest($request), 'doctor_screen');
        $this->authorize('view', $visit);

        return response()->json(['visit' => $serializer->visit($visit->load(['serial', 'sessionInstance'])), 'writer_url' => route('panel.prescription.writer', ['visit' => $visit->public_id])], $visit->wasRecentlyCreated ? 201 : 200);
    }

    /** POST /panel/patients/{patient}/visits — an ad-hoc visit without a serial. */
    public function store(StartAdhocVisitRequest $request, Patient $patient, StartAdhocVisit $start, DraftSerializer $serializer, ActiveBranch $branch): JsonResponse
    {
        $this->authorize('view', $patient);
        $doctor = $request->user('web')->doctor;
        abort_if($doctor === null, 403);
        $visit = $start->handle($patient, $doctor, $branch->current() ?? $doctor->user->defaultBranch, Actor::fromRequest($request), VisitType::from((string) $request->validated('type', 'opd')));

        return response()->json(['visit' => $serializer->visit($visit), 'writer_url' => route('panel.prescription.writer', ['visit' => $visit->public_id])], 201);
    }

    /** GET /panel/visits/{visit} — the visit record + latest vitals (audited read). */
    public function show(Visit $visit, DraftSerializer $serializer): JsonResponse
    {
        $this->authorize('view', $visit);
        AuditLog::view($visit, ['screen' => 'panel.prescription.visits.show']);
        $visit->load(['serial', 'sessionInstance', 'latestVitals.recordedBy']);

        return response()->json(['visit' => $serializer->visit($visit), 'vitals' => $visit->latestVitals !== null ? $serializer->vitals($visit->latestVitals) : null]);
    }

    /** PATCH /panel/visits/{visit} — complaints / findings / diagnoses / follow-up / private notes. */
    public function update(UpdateVisitRequest $request, Visit $visit, UpdateVisit $update, DraftSerializer $serializer): JsonResponse
    {
        $visit = $update->handle($visit, $request->toData(), Actor::fromRequest($request));

        return response()->json(['visit' => $serializer->visit($visit->load(['serial', 'sessionInstance']))]);
    }

    /** POST /panel/visits/{visit}/close */
    public function close(Request $request, Visit $visit, CloseVisit $close, DraftSerializer $serializer): JsonResponse
    {
        $this->authorize('close', $visit);
        $visit = $close->handle($visit, Actor::fromRequest($request));

        return response()->json(['visit' => $serializer->visit($visit->load(['serial', 'sessionInstance']))]);
    }
}
