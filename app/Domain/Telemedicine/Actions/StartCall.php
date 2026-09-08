<?php

declare(strict_types=1);

namespace App\Domain\Telemedicine\Actions;

use App\Domain\Prescription\Actions\StartVisit;
use App\Domain\Prescription\Exceptions\SerialHasNoPatient;
use App\Domain\Serials\Actions\CallSerial;
use App\Domain\Serials\Actions\StartConsultation;
use App\Domain\Serials\Enums\SerialStatus;
use App\Domain\Serials\Exceptions\IllegalTransition;
use App\Domain\Shared\Actor;
use App\Domain\Telemedicine\Data\RoomSpec;
use App\Domain\Telemedicine\Enums\RoomStatus;
use App\Domain\Telemedicine\Events\CallStarted;
use App\Domain\Telemedicine\Exceptions\RoomNotJoinable;
use App\Domain\Telemedicine\Services\TelemedicineAuditor;
use App\Domain\Telemedicine\Services\VideoProviderManager;
use App\Domain\Telemedicine\Services\VisitLink;
use App\Models\Tenant\Serial;
use App\Models\Tenant\TelemedicineRoom;
use App\Models\Tenant\TelemedicineSession;
use App\Tenancy\Facades\Tenancy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * The doctor starts the consultation. Everything clinical about it is the ORDINARY path (BRIEF §5.K — there is no
 * separate telemedicine flow), composed here rather than reimplemented:
 *
 *   1. `CallSerial` (Serials) if the serial is not yet `in_consultation`. That is the same action the doctor's
 *      screen and the reception desk use, so the live queue updates, "now serving" moves, the auto-no-show sweep
 *      runs and `SerialCalled` fires — which is what makes the visit appear.
 *   2. `SerialCalled` → `StartVisitOnSerialCalled` (Prescription) opens the `visits` row. `StartVisit` is called
 *      here as well, because it is idempotent and the room must have a visit even when the serial was called
 *      minutes ago from the queue screen.
 *   3. `StartConsultation` (Serials) stamps `consultation_started_at` — the same stamp the in-person writer makes.
 *   4. Only then does anything telemedicine-specific happen: the provider room, the room row, the session row.
 *
 * Reconnects create a NEW `telemedicine_sessions` row (SCHEMA §3.8); a second start while a call is live returns
 * the live row instead, so a double click does not fork the record.
 */
final class StartCall
{
    public function __construct(
        private readonly VideoProviderManager $providers,
        private readonly CallSerial $callSerial,
        private readonly StartConsultation $startConsultation,
        private readonly StartVisit $startVisit,
        private readonly TelemedicineAuditor $auditor,
        private readonly VisitLink $visits,
    ) {}

    public function handle(TelemedicineRoom $room, Actor $actor): TelemedicineSession
    {
        if ($room->status->isTerminal()) {
            throw new RoomNotJoinable($room->status);
        }

        $serial = $this->serialFor($room);
        $visitId = $serial === null ? null : $this->driveSerialLifecycle($serial, $actor);

        $provider = $this->providers->driver();
        $providerRoom = $provider->createRoom(new RoomSpec(
            roomName: $room->room_name,
            maxParticipants: (int) config('telemedicine.room.max_participants', 4),
            emptyTimeoutSeconds: (int) config('telemedicine.room.empty_timeout', 900),
            maxMinutes: $room->maxMinutes(),
            recording: $room->recordingAllowed(),
        ));

        $session = DB::transaction(function () use ($room, $visitId, $providerRoom): TelemedicineSession {
            /** @var TelemedicineRoom $locked */
            $locked = TelemedicineRoom::query()->whereKey($room->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== RoomStatus::Open) {
                $locked->forceFill(['status' => RoomStatus::Open, 'opened_at' => now()])->save();
            }

            $live = TelemedicineSession::query()->where('telemedicine_room_id', $locked->id)->whereNull('ended_at')->orderByDesc('id')->first();

            if ($live !== null) {
                if ($live->visit_id === null && $visitId !== null) {
                    $live->forceFill(['visit_id' => $visitId])->save();
                }

                return $live;
            }

            $session = new TelemedicineSession;
            $session->fill([
                'telemedicine_room_id' => $locked->id,
                'visit_id' => $visitId,
                'started_at' => now(),
                'participants' => [],
                'provider_session_id' => $providerRoom->sid,
            ]);
            $session->save();

            return $session;
        });

        $room->refresh();
        $this->auditor->opened($room);

        DB::afterCommit(fn () => CallStarted::dispatch((int) Tenancy::id(), $room->id, $session->id, $session->visit_id, $serial?->id));

        return $session;
    }

    /** The ordinary serial lifecycle — call, then stamp the consultation start. Returns the ordinary visit's id. */
    private function driveSerialLifecycle(Serial $serial, Actor $actor): ?int
    {
        if ($serial->status !== SerialStatus::InConsultation) {
            $serial = $this->callSerial->handle($serial, $actor);
        }

        $visitId = null;

        try {
            $visitId = $this->startVisit->handle($serial->loadMissing('sessionInstance'), $actor, 'telemedicine_call')->id;
        } catch (SerialHasNoPatient) {
            Log::channel('clinical')->info('telemedicine: serial without patient', ['serial_id' => $serial->id]);
        }

        try {
            $this->startConsultation->handle($serial, $actor);
        } catch (IllegalTransition) {
            // called and completed elsewhere in between — the serial state is Serials' business, not ours
        }

        return $visitId ?? $this->visits->idForSerial($serial->id);
    }

    private function serialFor(TelemedicineRoom $room): ?Serial
    {
        $serialId = $room->appointment->serial_id;

        return $serialId === null ? null : Serial::query()->with('sessionInstance')->find($serialId);
    }
}
