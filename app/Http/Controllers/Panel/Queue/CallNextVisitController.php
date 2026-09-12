<?php

declare(strict_types=1);

namespace App\Http\Controllers\Panel\Queue;

use App\Domain\Prescription\Actions\StartVisit;
use App\Domain\Prescription\Exceptions\SerialHasNoPatient;
use App\Domain\Prescription\Services\DraftSerializer;
use App\Domain\Queue\Exceptions\ChamberOccupied;
use App\Domain\Serials\Actions\CallNext;
use App\Domain\Serials\Enums\SerialStatus;
use App\Domain\Shared\Actor;
use App\Http\Controllers\Controller;
use App\Http\Resources\Serials\SerialResource;
use App\Models\Tenant\Serial;
use App\Models\Tenant\SessionInstance;
use App\Models\Tenant\User;
use App\Models\Tenant\Visit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST /panel/queue/sessions/{session}/call-next-visit (`panel.queue.call-next-visit`) — the post-issue bar's
 * "Call next patient" (BRIEF §5.G: issue → next patient, nothing more than two clicks deep). One request does
 * what the doctor screen does in two: `CallNext` for the session, then the called serial's visit through the
 * same idempotent `StartVisit` the doctor screen's Prescribe uses, and the writer URL when this user may write it.
 *
 *   { called: SerialResource|null, visit: VisitRow|null, writer_url: string|null, waiting_booked: n }
 *
 * `called: null` is "no one has arrived" — not an error. A serial still `in_consultation` on the session IS one
 * (409 `queue.chamber_occupied`): issuing completes the consultation through CompleteConsultationOnPrescriptionIssued,
 * so a chamber that is still occupied means the previous patient was not the issued one (or the issue did not
 * complete it) and the doctor must complete or return them before the next is called — never two in the chamber
 * by accident. Authorisation is the session's `callNext` policy: the doctor on their own session, or an operator.
 */
final class CallNextVisitController extends Controller
{
    public function __invoke(Request $request, SessionInstance $session, CallNext $callNext, StartVisit $startVisit, DraftSerializer $serializer): JsonResponse
    {
        $this->authorize('callNext', $session);

        // ANY serial still in consultation, not merely `now_serving`: a serial that was called and then passed
        // over (the desk called someone else on top of it) is no longer now_serving but is still, as far as the
        // engine is concerned, with the doctor. Calling the next patient on top of that is the thing this refuses.
        $serving = Serial::query()
            ->where('session_instance_id', $session->id)
            ->where('status', SerialStatus::InConsultation->value)
            ->orderBy('position')->orderBy('number')
            ->first();

        if ($serving !== null) {
            throw new ChamberOccupied($serving);
        }

        $actor = Actor::fromRequest($request);
        $result = $callNext->handle($session, $actor);
        $called = $result['called'];

        if ($called === null) {
            return response()->json(['called' => null, 'visit' => null, 'writer_url' => null, 'waiting_booked' => $result['waiting_booked']]);
        }

        // The SerialCalled listener has normally opened the visit already (StartVisitOnSerialCalled); this is the
        // same idempotent door. A serial without a patient (a bare counter slip) has no visit and no writer.
        try {
            $visit = $startVisit->handle($called, $actor, 'doctor_screen');
        } catch (SerialHasNoPatient) {
            $visit = null;
        }

        $user = $request->user('web');
        $writer = $visit instanceof Visit && $user instanceof User && $user->can('write', $visit)
            ? route('panel.prescription.writer', ['visit' => $visit->public_id])
            : null;

        return response()->json([
            'called' => new SerialResource($called->load('sessionInstance')),
            'visit' => $visit === null ? null : $serializer->visit($visit->load(['serial', 'sessionInstance'])),
            'writer_url' => $writer,
            'waiting_booked' => $result['waiting_booked'],
        ]);
    }
}
