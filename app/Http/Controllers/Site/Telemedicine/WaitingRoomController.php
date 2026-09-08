<?php

declare(strict_types=1);

namespace App\Http\Controllers\Site\Telemedicine;

use App\Domain\Queue\Services\QueueStateRepository;
use App\Domain\Telemedicine\Enums\ParticipantRole;
use App\Http\Controllers\Controller;
use App\Http\Resources\Telemedicine\RoomStateResource;
use App\Models\Tenant\SessionInstance;
use App\Models\Tenant\TelemedicineRoom;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The patient's waiting room and call screen (`site.telemedicine.room`).
 *
 * The queue half of this screen is NOT re-implemented: the first paint embeds the Queue module's own
 * `QueueState` document and the client hands it straight to `useQueueState()`, which then keeps it live over the
 * existing public queue channel and falls back to the existing `GET /queue/{doctorSlug}/state` poll. A patient at
 * home therefore sees the identical "now serving / patients ahead / estimated time" a patient in the corridor
 * sees, from the same builder, with the same version dedupe and the same 5-second fallback (REALTIME.md §4–§6).
 */
final class WaitingRoomController extends Controller
{
    public function show(Request $request, TelemedicineRoom $room, QueueStateRepository $queue): Response
    {
        $this->authorisePatient($request, $room);
        $room->loadMissing(['appointment.doctor', 'appointment.sessionInstance', 'appointment.branch']);
        $instance = SessionInstance::query()->find($room->appointment->session_instance_id);

        return Inertia::render('Telemedicine/Room', [
            'telemedicine' => (new RoomStateResource($room, ParticipantRole::Patient))->toArray($request),
            'queue_state' => $this->queueState($queue, $instance),
            'clinic_phone' => $room->appointment->branch->phone,
        ]);
    }

    public function state(Request $request, TelemedicineRoom $room): JsonResponse
    {
        $this->authorisePatient($request, $room);

        return response()->json((new RoomStateResource($room, ParticipantRole::Patient))->toArray($request));
    }

    /**
     * Best-effort: the embedded document only saves the first poll. If Redis is unreachable the page still
     * renders and `useQueueState()` falls back to the public poll endpoint — the whole point of that fallback.
     *
     * @return array<string, mixed>|null
     */
    private function queueState(QueueStateRepository $queue, ?SessionInstance $instance): ?array
    {
        if ($instance === null) {
            return null;
        }

        try {
            return $queue->state($instance);
        } catch (\Throwable) {
            return null;
        }
    }

    /** The patient guard alone is not enough: it must be THIS appointment's patient (or a household member of it). */
    private function authorisePatient(Request $request, TelemedicineRoom $room): void
    {
        $patientId = $request->user('patient')?->getKey();

        abort_if($patientId === null, 403);
        abort_unless((int) $patientId === $room->appointment->patient_id, 403);
    }
}
