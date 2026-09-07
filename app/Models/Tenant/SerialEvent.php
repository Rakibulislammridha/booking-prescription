<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use App\Domain\Serials\Enums\ActorType;
use App\Domain\Serials\Enums\SerialEventType;
use Carbon\CarbonImmutable;
use Database\Factories\Tenant\SerialEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Immutable log of every serial transition, reorder, priority change, transfer and session-level pool/block event
 * (SCHEMA §3.3). serial_id is NULL for session-level rows. Append-only: no update/delete path.
 *
 * @property int $id
 * @property int|null $serial_id
 * @property int $session_instance_id
 * @property SerialEventType $type
 * @property string|null $from_status
 * @property string|null $to_status
 * @property int|null $from_position
 * @property int|null $to_position
 * @property string|null $from_priority
 * @property string|null $to_priority
 * @property ActorType $actor_type
 * @property int|null $actor_user_id
 * @property int|null $actor_patient_id
 * @property int|null $reception_device_id
 * @property string|null $client_event_id
 * @property string|null $reason
 * @property array<string, mixed> $meta
 * @property string|null $ip
 * @property string|null $user_agent
 * @property CarbonImmutable $occurred_at
 * @property-read Serial|null $serial
 * @property-read SessionInstance $sessionInstance
 */
final class SerialEvent extends TenantModel
{
    /** @use HasFactory<SerialEventFactory> */
    use HasFactory;

    public $timestamps = false;

    protected static string $factory = SerialEventFactory::class;

    protected $table = 'serial_events';

    protected $fillable = [
        'serial_id', 'session_instance_id', 'type', 'from_status', 'to_status', 'from_position', 'to_position', 'from_priority', 'to_priority',
        'actor_type', 'actor_user_id', 'actor_patient_id', 'reception_device_id', 'client_event_id', 'reason', 'meta', 'ip', 'user_agent', 'occurred_at',
    ];

    protected static function booted(): void
    {
        self::updating(fn () => throw new LogicException('serial_events is append-only.'));
        self::deleting(fn () => throw new LogicException('serial_events is append-only.'));
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => SerialEventType::class,
            'from_position' => 'integer',
            'to_position' => 'integer',
            'actor_type' => ActorType::class,
            'meta' => 'array',
            'occurred_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<Serial, $this> */
    public function serial(): BelongsTo
    {
        return $this->belongsTo(Serial::class);
    }

    /** @return BelongsTo<SessionInstance, $this> */
    public function sessionInstance(): BelongsTo
    {
        return $this->belongsTo(SessionInstance::class);
    }
}
