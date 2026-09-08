<?php

declare(strict_types=1);

namespace App\Http\Controllers\Panel\Telemedicine;

use App\Domain\Shared\Actor;
use App\Domain\Telemedicine\Actions\SetRecording;
use App\Domain\Telemedicine\Enums\ParticipantRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Panel\Telemedicine\ToggleRecordingRequest;
use App\Http\Resources\Telemedicine\RoomStateResource;
use App\Models\Tenant\TelemedicineRoom;
use Illuminate\Http\JsonResponse;

/** Start/stop recording. Refused (never silently ignored) without the clinic's switch and the patient's consent. */
final class RecordingController extends Controller
{
    public function update(ToggleRecordingRequest $request, TelemedicineRoom $room, SetRecording $setRecording): JsonResponse
    {
        $this->authorize('record', $room);
        $setRecording->handle($room, $request->boolean('on'), Actor::fromRequest($request));

        return response()->json((new RoomStateResource($room->refresh(), ParticipantRole::Doctor))->toArray($request));
    }
}
