<?php

declare(strict_types=1);

namespace App\Domain\Telemedicine\Services;

use App\Domain\Serials\Enums\SerialStatus;
use App\Domain\Telemedicine\Enums\ParticipantRole;
use App\Domain\Telemedicine\Enums\RoomStatus;
use App\Models\Tenant\Serial;
use App\Models\Tenant\SessionInstance;
use App\Models\Tenant\TelemedicineRoom;
use App\Models\Tenant\TelemedicineSession;
use App\Tenancy\Facades\Tenancy;

/**
 * ONE document, polled by the waiting room and by the doctor's console, describing the call and nothing else.
 *
 * It deliberately does NOT restate the queue: how many patients are ahead, who is being served and when the
 * patient is likely to be called all come from the Queue module's `QueueState` over its existing public channel
 * and its existing `GET /queue/{doctorSlug}/state` endpoint. A waiting patient at home sees exactly what a
 * waiting patient in the corridor sees, because it is literally the same document from the same source
 * (BRIEF §5.E, REALTIME.md §4) — this module adds only what the corridor has no equivalent of: is the room open,
 * is the doctor actually in it, and may I ask for a token yet.
 */
final class RoomStateBuilder
{
    /** @return array<string, mixed> */
    public function build(TelemedicineRoom $room, ?ParticipantRole $viewer = null): array
    {
        $room->loadMissing(['appointment.doctor', 'appointment.sessionInstance']);
        $appointment = $room->appointment;
        $serialId = $appointment->serial_id;
        $session = TelemedicineSession::query()->where('telemedicine_room_id', $room->id)->orderByDesc('id')->first();
        $live = $session !== null && $session->isLive() ? $session : null;
        $serial = $serialId === null ? null : Serial::query()->find($serialId);
        $doctor = $appointment->doctor;
        $instance = SessionInstance::query()->find($appointment->session_instance_id);

        return [
            'room' => $room->room_name,
            'status' => $room->status->value,
            'provider' => $room->provider->value,
            'scheduled_at' => $room->scheduled_at->toIso8601String(),
            'opened_at' => $room->opened_at?->toIso8601String(),
            'ended_at' => $room->ended_at?->toIso8601String(),
            'max_minutes' => $room->maxMinutes(),
            'recording' => [
                'allowed' => $room->recordingAllowed(),
                'active' => (bool) ($room->settings['recording_active'] ?? false),
            ],
            'can_join' => $room->status === RoomStatus::Open && $live !== null,
            'presence' => [
                'doctor' => $live?->hasJoined(ParticipantRole::Doctor) ?? false,
                'patient' => $live?->hasJoined(ParticipantRole::Patient) ?? false,
            ],
            'call' => $session === null ? null : [
                'started_at' => $session->started_at->toIso8601String(),
                'ended_at' => $session->ended_at?->toIso8601String(),
                'duration_seconds' => $session->duration_seconds ?? (int) $session->started_at->diffInSeconds(now()),
                'end_reason' => $session->end_reason?->value,
            ],
            'serial' => $serial === null ? null : [
                'public_id' => $serial->public_id,
                'code' => $serial->display_code,
                'status' => $serial->status->value,
                'is_being_seen' => $serial->status === SerialStatus::InConsultation,
            ],
            'doctor' => [
                'public_id' => $doctor->public_id,
                'slug' => $doctor->slug,
                'name' => $doctor->name,
                'name_bn' => $doctor->name_bn,
            ],
            'queue' => $instance === null ? null : [
                'tenant_public_id' => Tenancy::current()?->public_id,
                'session_public_id' => $instance->public_id,
                'doctor_slug' => $doctor->slug,
            ],
            'viewer' => $viewer?->value,
            'server_time' => now()->toIso8601String(),
        ];
    }
}
