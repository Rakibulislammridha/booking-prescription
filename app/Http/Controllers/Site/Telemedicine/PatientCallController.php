<?php

declare(strict_types=1);

namespace App\Http\Controllers\Site\Telemedicine;

use App\Domain\Shared\Actor;
use App\Domain\Telemedicine\Actions\JoinCall;
use App\Domain\Telemedicine\Actions\LeaveCall;
use App\Domain\Telemedicine\Actions\RecordCallQuality;
use App\Domain\Telemedicine\Enums\ParticipantRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Site\Telemedicine\PatientQualityRequest;
use App\Http\Resources\Telemedicine\RoomStateResource;
use App\Models\Tenant\TelemedicineRoom;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The patient's three call verbs, all JSON, all on the `patient` guard.
 *
 * `token` is the only one that mints a credential, and it can only ever mint a PATIENT one: the role is a
 * constant here, not a parameter, so there is no request shape in which a patient asks for a doctor's grants.
 */
final class PatientCallController extends Controller
{
    public function token(Request $request, TelemedicineRoom $room, JoinCall $join): JsonResponse
    {
        $this->authorisePatient($request, $room);
        $device = mb_substr((string) $request->userAgent(), 0, 64);
        $token = $join->handle($room, ParticipantRole::Patient, Actor::fromRequest($request), $device === '' ? 'web' : $device);

        return response()->json($token->toArray());
    }

    public function leave(Request $request, TelemedicineRoom $room, LeaveCall $leave): JsonResponse
    {
        $this->authorisePatient($request, $room);
        $leave->handle($room, ParticipantRole::Patient, Actor::fromRequest($request));

        return response()->json((new RoomStateResource($room->refresh(), ParticipantRole::Patient))->toArray($request));
    }

    public function quality(PatientQualityRequest $request, TelemedicineRoom $room, RecordCallQuality $record): JsonResponse
    {
        $this->authorisePatient($request, $room);
        $record->handle($room, $request->stats());

        return response()->json(['ok' => true]);
    }

    private function authorisePatient(Request $request, TelemedicineRoom $room): void
    {
        $patientId = $request->user('patient')?->getKey();

        abort_if($patientId === null, 403);
        abort_unless((int) $patientId === $room->appointment->patient_id, 403);
    }
}
