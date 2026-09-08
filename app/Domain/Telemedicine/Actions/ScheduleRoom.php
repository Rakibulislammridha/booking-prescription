<?php

declare(strict_types=1);

namespace App\Domain\Telemedicine\Actions;

use App\Domain\Booking\Enums\BookingChannel;
use App\Domain\Telemedicine\Data\RoomSpec;
use App\Domain\Telemedicine\Enums\RoomStatus;
use App\Domain\Telemedicine\Events\TelemedicineInviteIssued;
use App\Domain\Telemedicine\Exceptions\NotATelemedicineAppointment;
use App\Domain\Telemedicine\Services\JoinLink;
use App\Domain\Telemedicine\Services\RoomName;
use App\Domain\Telemedicine\Services\TelemedicineSettings;
use App\Domain\Telemedicine\Services\VideoProviderManager;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\SessionInstance;
use App\Models\Tenant\TelemedicineRoom;
use App\Tenancy\Facades\Tenancy;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * The room row for a telemedicine booking — created the moment the appointment exists, so the patient can be sent
 * a join link straight away and can open the waiting room whenever they like.
 *
 * The PROVIDER room is deliberately NOT created here: a LiveKit room with an empty-timeout would sit open for
 * days between booking and consultation. `OpenRoom` creates it when the doctor actually starts.
 *
 * Idempotent through `telemedicine_rooms_appointment_id_uniq`, including under a race (a double-submitted
 * booking dispatches the listener twice).
 */
final class ScheduleRoom
{
    public function __construct(
        private readonly TelemedicineSettings $settings,
        private readonly VideoProviderManager $providers,
        private readonly JoinLink $links,
    ) {}

    public function handle(Appointment $appointment): TelemedicineRoom
    {
        if ($appointment->channel !== BookingChannel::Telemedicine && ! $appointment->is_telemedicine) {
            throw new NotATelemedicineAppointment;
        }

        $existing = TelemedicineRoom::query()->where('appointment_id', $appointment->id)->first();

        if ($existing !== null) {
            return $existing;
        }

        $credentials = $this->settings->credentials();
        $spec = new RoomSpec(
            roomName: RoomName::generate(),
            maxParticipants: (int) config('telemedicine.room.max_participants', 4),
            emptyTimeoutSeconds: (int) config('telemedicine.room.empty_timeout', 900),
            maxMinutes: $credentials->maxMinutes,
            recording: $credentials->recording,
        );

        try {
            $room = DB::transaction(function () use ($appointment, $spec): TelemedicineRoom {
                $room = new TelemedicineRoom;
                $room->fill([
                    'appointment_id' => $appointment->id,
                    'provider' => $this->providers->driver()->key(),
                    'room_name' => $spec->roomName,
                    'status' => RoomStatus::Scheduled,
                    'scheduled_at' => $this->scheduledAt($appointment),
                    'settings' => $spec->toSettings(),
                ]);
                $room->save();

                return $room;
            });
        } catch (QueryException $e) {
            $again = TelemedicineRoom::query()->where('appointment_id', $appointment->id)->first();

            if ($again !== null) {
                return $again;                       // lost the race: the unique index held
            }

            throw $e;
        }

        $expiresAt = $this->links->expiresAt($room);
        $room->forceFill(['patient_join_url_expires_at' => $expiresAt])->save();
        $url = $this->links->for($room);

        DB::afterCommit(fn () => TelemedicineInviteIssued::dispatch(
            (int) Tenancy::id(),
            $room->id,
            $appointment->id,
            $appointment->patient_id,
            $appointment->serial_id,
            $url,
            $expiresAt->toIso8601String(),
        ));

        return $room;
    }

    private function scheduledAt(Appointment $appointment): CarbonImmutable
    {
        if ($appointment->slot_start_at !== null) {
            return $appointment->slot_start_at;
        }

        $plannedStart = SessionInstance::query()->whereKey($appointment->session_instance_id)->value('planned_start_at');

        if ($plannedStart !== null) {
            return CarbonImmutable::parse((string) $plannedStart);
        }

        return ($appointment->scheduled_date ?? CarbonImmutable::now())->startOfDay()->addHours(10);
    }
}
