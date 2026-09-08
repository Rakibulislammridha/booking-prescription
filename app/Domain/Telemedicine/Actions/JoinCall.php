<?php

declare(strict_types=1);

namespace App\Domain\Telemedicine\Actions;

use App\Domain\Shared\Actor;
use App\Domain\Telemedicine\Data\JoinToken;
use App\Domain\Telemedicine\Data\TokenRequest;
use App\Domain\Telemedicine\Enums\ParticipantRole;
use App\Domain\Telemedicine\Enums\RoomStatus;
use App\Domain\Telemedicine\Events\ParticipantPresenceChanged;
use App\Domain\Telemedicine\Exceptions\CallNotLive;
use App\Domain\Telemedicine\Exceptions\RoomNotJoinable;
use App\Domain\Telemedicine\Services\TelemedicineAuditor;
use App\Domain\Telemedicine\Services\TelemedicineSettings;
use App\Domain\Telemedicine\Services\VideoProviderManager;
use App\Models\Tenant\TelemedicineRoom;
use App\Models\Tenant\TelemedicineSession;
use App\Tenancy\Facades\Tenancy;
use Illuminate\Support\Facades\DB;

/**
 * Mint one scoped, short-lived credential and record the participant as present.
 *
 * The GRANTS are never chosen by the caller: `TokenRequest::forRole()` derives them from the role, so there is
 * no code path in which a patient's token can carry `roomRecord` or `roomAdmin`. Recording additionally requires
 * the clinic to have turned it on for the room.
 *
 * A patient may only be handed a token once the room is OPEN — "the doctor is not here yet" is a waiting-room
 * state, not a call. The doctor's own token opens nothing either: `StartCall` does that.
 */
final class JoinCall
{
    public function __construct(
        private readonly VideoProviderManager $providers,
        private readonly TelemedicineSettings $settings,
        private readonly TelemedicineAuditor $auditor,
    ) {}

    public function handle(TelemedicineRoom $room, ParticipantRole $role, Actor $actor, string $device = 'web'): JoinToken
    {
        if ($room->status !== RoomStatus::Open) {
            throw new RoomNotJoinable($room->status);
        }

        $session = TelemedicineSession::query()
            ->where('telemedicine_room_id', $room->id)
            ->whereNull('ended_at')
            ->orderByDesc('id')
            ->first();

        if ($session === null) {
            throw new CallNotLive;
        }

        $ttl = $this->settings->credentials()->tokenTtlSeconds;
        $token = $this->providers->driver()->mintToken(TokenRequest::forRole(
            roomName: $room->room_name,
            role: $role,
            identity: $role->identityFor($this->identitySeed($room, $role)),
            displayName: $this->displayName($room, $role),
            ttlSeconds: $ttl,
            recordingAllowed: $room->recordingAllowed(),
        ));

        $this->markPresent($session, $role, $device);
        $room->forceFill([
            $role === ParticipantRole::Doctor ? 'doctor_join_url_expires_at' : 'patient_join_url_expires_at' => $token->expiresAt,
        ])->save();

        $this->auditor->joined($session, $room, $role, $device);
        DB::afterCommit(fn () => ParticipantPresenceChanged::dispatch((int) Tenancy::id(), $room->id, $session->id, $role, true));

        return $token;
    }

    /** Adds (or refreshes) the role's live entry in `participants` under a row lock, so two tabs cannot duplicate it. */
    private function markPresent(TelemedicineSession $session, ParticipantRole $role, string $device): void
    {
        DB::transaction(function () use ($session, $role, $device): void {
            /** @var TelemedicineSession $locked */
            $locked = TelemedicineSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();
            $participants = $locked->participants;

            if ($locked->presenceOf($role) !== null) {
                return;
            }

            $participants[] = ['role' => $role->value, 'joined_at' => now()->toIso8601String(), 'left_at' => null, 'device' => mb_substr($device, 0, 64)];
            $locked->forceFill(['participants' => array_values($participants)])->save();
            $session->setAttribute('participants', $locked->participants);
        });
    }

    /** Stable, opaque and per-room: the identity is never the patient's mobile or the doctor's user id. */
    private function identitySeed(TelemedicineRoom $room, ParticipantRole $role): string
    {
        return substr(hash('sha256', $room->room_name.'|'.$role->value.'|'.config('app.key')), 0, 24);
    }

    private function displayName(TelemedicineRoom $room, ParticipantRole $role): string
    {
        $appointment = $room->appointment;

        return $role === ParticipantRole::Doctor ? (string) $appointment->doctor->name : (string) $appointment->patient->name;
    }
}
