<?php

declare(strict_types=1);

namespace App\Domain\Serials\Services;

use App\Domain\Serials\Enums\ActorType;
use App\Domain\Serials\Enums\SerialEventType;
use App\Domain\Shared\Actor;
use App\Models\Tenant\Serial;
use App\Models\Tenant\SerialEvent;
use App\Models\Tenant\SessionInstance;
use Illuminate\Http\Request;

/**
 * Writes serial_events rows (SCHEMA §3.3): the JSON details go in `meta`, the actor in actor_type/actor_user_id/
 * actor_patient_id/reception_device_id, `{"source": …}` from the Actor is merged into meta. Always inside the caller's
 * transaction (invariant I-EVENTS).
 */
final class SerialEventWriter
{
    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $columns  from_status, to_status, from_position, to_position, from_priority, to_priority, reason, client_event_id
     */
    public function write(SessionInstance|int $session, ?Serial $serial, SerialEventType $type, array $meta = [], ?Actor $actor = null, array $columns = []): SerialEvent
    {
        $actor ??= Actor::system();
        $request = app()->bound('request') ? app('request') : null;

        $event = new SerialEvent;
        $event->forceFill(array_merge([
            'serial_id' => $serial?->id,
            'session_instance_id' => $session instanceof SessionInstance ? $session->id : $session,
            'type' => $type,
            'actor_type' => ActorType::fromActor($actor),
            'actor_user_id' => $actor->userId,
            'actor_patient_id' => $actor->patientId,
            'reception_device_id' => $actor->deviceId,
            'meta' => array_merge($meta, ['source' => $actor->source]),
            'ip' => $actor->ip,
            'user_agent' => $request instanceof Request ? $request->userAgent() : null,
            'occurred_at' => now(),
        ], $columns));
        $event->save();

        return $event;
    }
}
