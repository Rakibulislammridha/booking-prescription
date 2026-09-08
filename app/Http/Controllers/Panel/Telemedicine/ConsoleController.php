<?php

declare(strict_types=1);

namespace App\Http\Controllers\Panel\Telemedicine;

use App\Domain\Clinic\Services\ActiveBranch;
use App\Domain\Prescription\Actions\CreateDraftPrescription;
use App\Domain\Prescription\Services\WriterPayloadBuilder;
use App\Domain\Shared\Actor;
use App\Domain\Telemedicine\Enums\ParticipantRole;
use App\Domain\Telemedicine\Enums\RoomStatus;
use App\Domain\Telemedicine\Services\RoomStateBuilder;
use App\Domain\Telemedicine\Services\VisitLink;
use App\Http\Controllers\Controller;
use App\Http\Resources\Telemedicine\RoomStateResource;
use App\Http\Resources\Telemedicine\RoomSummaryResource;
use App\Models\Tenant\AuditLog;
use App\Models\Tenant\TelemedicineRoom;
use App\Support\Clock;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The doctor's two telemedicine screens.
 *
 * `show` is the one that matters, and its whole design is one decision: the props are the ORDINARY writer props,
 * built by the Prescription module's own `WriterPayloadBuilder` from the ORDINARY visit, plus a `telemedicine`
 * key. The page component then renders the untouched `Prescription/Writer` next to a video rail. There is no
 * telemedicine prescription path, no forked payload and no edit to `resources/js/panel/Pages/Prescription/**`
 * (BRIEF §5.K).
 *
 * Before the call has started there is no visit yet, so `show` renders the same page in its "pre-call" state and
 * the writer appears the moment `POST …/start` has run the ordinary serial → visit lifecycle.
 */
final class ConsoleController extends Controller
{
    public function index(Request $request, RoomStateBuilder $state): Response
    {
        $this->authorize('viewAny', TelemedicineRoom::class);
        $date = Clock::today();

        $rooms = TelemedicineRoom::query()
            ->with(['appointment.patient', 'appointment.doctor', 'appointment.serial'])
            ->whereBetween('scheduled_at', [$date->startOfDay()->utc(), $date->endOfDay()->utc()])
            ->orderBy('scheduled_at')
            ->orderBy('id')
            ->get()
            ->filter(fn (TelemedicineRoom $room) => $request->user('web')?->can('view', $room) ?? false)
            ->values();

        return Inertia::render('Telemedicine/Index', [
            'date' => $date->toDateString(),
            'rooms' => RoomSummaryResource::collection($rooms)->toArray($request),
        ]);
    }

    public function show(
        Request $request,
        TelemedicineRoom $room,
        VisitLink $visits,
        CreateDraftPrescription $createDraft,
        WriterPayloadBuilder $payload,
        ActiveBranch $branch,
    ): Response {
        $this->authorize('view', $room);
        $room->loadMissing(['appointment.patient', 'appointment.doctor', 'appointment.sessionInstance']);
        AuditLog::view($room, ['screen' => 'panel.telemedicine.console']);

        $visit = $visits->forRoom($room);
        $writer = null;

        if ($visit !== null) {
            $doctor = $request->user('web')?->doctor;

            if ($doctor !== null) {
                AuditLog::view($visit, ['screen' => 'panel.telemedicine.console']);
                $draft = $createDraft->handle($visit, $doctor, Actor::fromRequest($request));
                $writer = $payload->build($visit, $draft, $doctor, $branch->current()?->id);
            }
        }

        return Inertia::render('Telemedicine/Console', [
            'telemedicine' => (new RoomStateResource($room, ParticipantRole::Doctor))->toArray($request),
            'can_consult' => $request->user('web')?->can('consult', $room) ?? false,
            'writer' => $writer,
            'writer_url' => $visit === null ? null : route('panel.prescription.writer', ['visit' => $visit->public_id]),
            'ended' => $room->status === RoomStatus::Ended,
        ]);
    }
}
