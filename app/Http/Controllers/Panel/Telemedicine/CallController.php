<?php

declare(strict_types=1);

namespace App\Http\Controllers\Panel\Telemedicine;

use App\Domain\Shared\Actor;
use App\Domain\Telemedicine\Actions\EndCall;
use App\Domain\Telemedicine\Actions\JoinCall;
use App\Domain\Telemedicine\Actions\LeaveCall;
use App\Domain\Telemedicine\Actions\RecordCallQuality;
use App\Domain\Telemedicine\Actions\StartCall;
use App\Domain\Telemedicine\Enums\ParticipantRole;
use App\Domain\Telemedicine\Enums\SessionEndReason;
use App\Http\Controllers\Controller;
use App\Http\Requests\Panel\Telemedicine\EndCallRequest;
use App\Http\Requests\Panel\Telemedicine\QualityReportRequest;
use App\Http\Resources\Telemedicine\RoomStateResource;
use App\Models\Tenant\TelemedicineRoom;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The doctor's side of a call. `start` and `end` are Inertia posts (they change the screen); the rest are the
 * writer-style panel JSON endpoints of CONVENTIONS §5 — they run every few seconds during a call and must not
 * re-render a page to say "still connected".
 */
final class CallController extends Controller
{
    public function start(Request $request, TelemedicineRoom $room, StartCall $start): RedirectResponse
    {
        $this->authorize('consult', $room);
        $start->handle($room, Actor::fromRequest($request));

        return redirect()->route('panel.telemedicine.console', ['room' => $room->room_name])
            ->with('flash.success', __('telemedicine.flash.started'));
    }

    public function token(Request $request, TelemedicineRoom $room, JoinCall $join): JsonResponse
    {
        $this->authorize('consult', $room);
        $token = $join->handle($room, ParticipantRole::Doctor, Actor::fromRequest($request), 'panel');

        return response()->json($token->toArray());
    }

    public function leave(Request $request, TelemedicineRoom $room, LeaveCall $leave): JsonResponse
    {
        $this->authorize('consult', $room);
        $leave->handle($room, ParticipantRole::Doctor, Actor::fromRequest($request));

        return response()->json((new RoomStateResource($room->refresh(), ParticipantRole::Doctor))->toArray($request));
    }

    public function end(EndCallRequest $request, TelemedicineRoom $room, EndCall $end): RedirectResponse
    {
        $this->authorize('consult', $room);
        $end->handle($room, $request->reason(), Actor::fromRequest($request));

        return $request->reason() === SessionEndReason::Dropped
            ? redirect()->route('panel.telemedicine.console', ['room' => $room->room_name])
            : redirect()->route('panel.telemedicine.index')->with('flash.success', __('telemedicine.flash.ended'));
    }

    public function state(Request $request, TelemedicineRoom $room): JsonResponse
    {
        $this->authorize('view', $room);

        return response()->json((new RoomStateResource($room, ParticipantRole::Doctor))->toArray($request));
    }

    public function quality(QualityReportRequest $request, TelemedicineRoom $room, RecordCallQuality $record): JsonResponse
    {
        $this->authorize('view', $room);
        $record->handle($room, $request->stats());

        return response()->json(['ok' => true]);
    }
}
