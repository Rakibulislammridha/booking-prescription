<?php

declare(strict_types=1);

namespace App\Domain\Telemedicine\Actions;

use App\Domain\Shared\Actor;
use App\Domain\Telemedicine\Data\ProviderWebhookEvent;
use App\Domain\Telemedicine\Enums\SessionEndReason;
use App\Domain\Telemedicine\Services\TelemedicineAuditor;
use App\Domain\Telemedicine\Services\VideoProviderManager;
use App\Models\Tenant\TelemedicineRoom;
use App\Models\Tenant\TelemedicineSession;
use Illuminate\Support\Facades\DB;

/**
 * A provider callback that has already been AUTHENTICATED by its driver (`verifyWebhook` returns null otherwise,
 * and this action never sees it). Presence from the provider is more trustworthy than the browser's own beacon —
 * a killed tab sends nothing — so it wins: `participant_left` closes the presence entry the client never closed.
 *
 * Returns NULL when the payload could not be authenticated (the controller answers 401, so a wrong secret is
 * loud), FALSE when it verified but matched nothing (202 — a provider must not retry forever because a room was
 * torn down on our side), TRUE when it was applied.
 */
final class HandleProviderWebhook
{
    public function __construct(
        private readonly VideoProviderManager $providers,
        private readonly LeaveCall $leave,
        private readonly EndCall $end,
        private readonly TelemedicineAuditor $auditor,
    ) {}

    /** @param  array<string, string>  $headers */
    public function handle(string $payload, array $headers): ?bool
    {
        $event = $this->providers->driver()->verifyWebhook($payload, $headers);

        if ($event === null) {
            return null;
        }

        $room = TelemedicineRoom::query()->where('room_name', $event->roomName)->first();

        if ($room === null) {
            return false;
        }

        return match ($event->type) {
            ProviderWebhookEvent::PARTICIPANT_JOINED => $this->noteJoin($room, $event),
            ProviderWebhookEvent::PARTICIPANT_LEFT => $event->role !== null && $this->leave->handle($room, $event->role, Actor::system()) !== null,
            ProviderWebhookEvent::ROOM_FINISHED => $this->finish($room),
            ProviderWebhookEvent::RECORDING_STARTED, ProviderWebhookEvent::RECORDING_FINISHED => $this->recording($room, $event),
            default => false,
        };
    }

    private function noteJoin(TelemedicineRoom $room, ProviderWebhookEvent $event): bool
    {
        $session = $this->liveSession($room);
        $role = $event->role;

        if ($session === null || $role === null) {
            return false;
        }

        if ($session->provider_session_id === null && $event->sid !== null) {
            $session->forceFill(['provider_session_id' => $event->sid])->save();
        }

        if ($session->hasJoined($role)) {
            return true;                                     // the client beacon already recorded it
        }

        DB::transaction(function () use ($session, $event, $role): void {
            /** @var TelemedicineSession $locked */
            $locked = TelemedicineSession::query()->whereKey($session->id)->lockForUpdate()->firstOrFail();
            $participants = $locked->participants;
            $participants[] = ['role' => $role->value, 'joined_at' => ($event->occurredAt ?? now())->toIso8601String(), 'left_at' => null, 'device' => 'provider'];
            $locked->forceFill(['participants' => array_values($participants)])->save();
        });

        $this->auditor->joined($session->refresh(), $room, $role, 'provider');

        return true;
    }

    private function finish(TelemedicineRoom $room): bool
    {
        if ($this->liveSession($room) === null) {
            return false;
        }

        $this->end->handle($room, SessionEndReason::Dropped, Actor::system());

        return true;
    }

    private function recording(TelemedicineRoom $room, ProviderWebhookEvent $event): bool
    {
        $session = $this->liveSession($room) ?? TelemedicineSession::query()->where('telemedicine_room_id', $room->id)->orderByDesc('id')->first();

        if ($session === null) {
            return false;
        }

        if ($event->recordingPath !== null) {
            $session->forceFill(['recording_path' => $event->recordingPath])->save();
        }

        $this->auditor->recording($session, $room, $event->type === ProviderWebhookEvent::RECORDING_STARTED ? 'started' : 'finished', $event->recordingPath);

        return true;
    }

    private function liveSession(TelemedicineRoom $room): ?TelemedicineSession
    {
        return TelemedicineSession::query()->where('telemedicine_room_id', $room->id)->whereNull('ended_at')->orderByDesc('id')->first();
    }
}
