<?php

declare(strict_types=1);

namespace App\Domain\Telemedicine\Actions;

use App\Domain\Serials\Actions\CompleteConsultation;
use App\Domain\Serials\Enums\SerialStatus;
use App\Domain\Shared\Actor;
use App\Domain\Telemedicine\Enums\RoomStatus;
use App\Domain\Telemedicine\Enums\SessionEndReason;
use App\Domain\Telemedicine\Events\CallEnded;
use App\Domain\Telemedicine\Exceptions\CallNotLive;
use App\Domain\Telemedicine\Services\TelemedicineAuditor;
use App\Domain\Telemedicine\Services\VideoProviderManager;
use App\Models\Tenant\Serial;
use App\Models\Tenant\TelemedicineRoom;
use App\Models\Tenant\TelemedicineSession;
use App\Tenancy\Facades\Tenancy;
use Illuminate\Support\Facades\DB;

/**
 * "End the call and complete the serial in one action" (BRIEF §5.K in-call surface).
 *
 * The completion itself is Serials' `CompleteConsultation` — the same action the doctor's screen calls after an
 * in-person consultation and the same one `PrescriptionIssued` triggers. It is idempotent here twice over: only
 * a `completed` end reason drives it, and only a serial still `in_consultation` is transitioned, so ending the
 * call after the prescription was already issued is a no-op rather than a second transition.
 *
 * A DROPPED call leaves the room open and the serial in consultation: the doctor rejoins and a new session row
 * records the second attempt (SCHEMA §3.8).
 */
final class EndCall
{
    public function __construct(
        private readonly VideoProviderManager $providers,
        private readonly CompleteConsultation $complete,
        private readonly TelemedicineAuditor $auditor,
    ) {}

    public function handle(TelemedicineRoom $room, SessionEndReason $reason, Actor $actor): TelemedicineSession
    {
        $session = TelemedicineSession::query()
            ->where('telemedicine_room_id', $room->id)
            ->whereNull('ended_at')
            ->orderByDesc('id')
            ->first();

        if ($session === null) {
            throw new CallNotLive;
        }

        $ended = DB::transaction(function () use ($session, $room, $reason): TelemedicineSession {
            /** @var TelemedicineSession $locked */
            $locked = TelemedicineSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();
            $now = now();
            $participants = array_map(function (array $entry) use ($now): array {
                $entry['left_at'] ??= $now->toIso8601String();

                return $entry;
            }, $locked->participants);

            $locked->forceFill([
                'ended_at' => $now,
                'duration_seconds' => max(0, (int) $locked->started_at->diffInSeconds($now)),
                'end_reason' => $reason,
                'participants' => array_values($participants),
            ])->save();

            if ($reason !== SessionEndReason::Dropped) {
                TelemedicineRoom::query()->whereKey($room->id)->lockForUpdate()->firstOrFail()->forceFill([
                    'status' => $reason === SessionEndReason::Cancelled ? RoomStatus::Cancelled : RoomStatus::Ended,
                    'ended_at' => $now,
                ])->save();
            }

            return $locked;
        }, attempts: 3);

        if ($reason !== SessionEndReason::Dropped) {
            $this->providers->driver()->closeRoom($room->room_name);
        }

        $this->auditor->ended($ended, $room, $reason, (int) $ended->duration_seconds);
        $this->completeSerial($room, $reason, $actor);
        $room->refresh();

        DB::afterCommit(fn () => CallEnded::dispatch(
            (int) Tenancy::id(), $room->id, $ended->id, $ended->visit_id, (int) $ended->duration_seconds, $reason,
        ));

        return $ended;
    }

    private function completeSerial(TelemedicineRoom $room, SessionEndReason $reason, Actor $actor): void
    {
        if (! $reason->completesTheSerial()) {
            return;
        }

        $serialId = $room->appointment->serial_id;
        $serial = $serialId === null ? null : Serial::query()->find($serialId);

        if ($serial === null || $serial->status !== SerialStatus::InConsultation) {
            return;
        }

        $this->complete->handle($serial, $actor);
    }
}
