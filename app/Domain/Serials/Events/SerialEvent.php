<?php

declare(strict_types=1);

namespace App\Domain\Serials\Events;

use App\Models\Tenant\Serial;
use App\Models\Tenant\SessionInstance;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Base of every Serials domain event (SERIAL_ENGINE §15): dispatched after commit; payload = ids + a frozen array
 * snapshot, because a queued listener must not rely on the model being unchanged. None of these broadcast — the Queue
 * module listens and emits its own wire events.
 */
abstract class SerialEvent implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public readonly int $serialId;

    public readonly int $sessionInstanceId;

    public readonly string $serialPublicId;

    public readonly string $sessionPublicId;

    /** @var array<string, mixed> */
    public readonly array $serial;

    public function __construct(Serial $serial, ?SessionInstance $session = null)
    {
        $this->serialId = $serial->id;
        $this->sessionInstanceId = $serial->session_instance_id;
        $this->serialPublicId = $serial->public_id;
        $this->sessionPublicId = $session !== null ? $session->public_id : (string) SessionInstance::query()->whereKey($serial->session_instance_id)->value('public_id');
        $this->serial = self::snapshot($serial);
    }

    /** @return array<string, mixed> */
    public static function snapshot(Serial $serial): array
    {
        return [
            'id' => $serial->id,
            'public_id' => $serial->public_id,
            'session_instance_id' => $serial->session_instance_id,
            'number' => $serial->number,
            'display_code' => $serial->display_code,
            'position' => $serial->position,
            'pool' => $serial->pool->value,
            'status' => $serial->status->value,
            'priority' => $serial->priority->value,
            'source' => $serial->source->value,
            'patient_id' => $serial->patient_id,
            'appointment_id' => $serial->appointment_id,
            'called_at' => $serial->called_at?->toIso8601String(),
            'completed_at' => $serial->completed_at?->toIso8601String(),
        ];
    }
}
