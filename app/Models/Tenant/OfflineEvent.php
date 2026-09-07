<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use App\Domain\Reception\Enums\ConflictReason;
use App\Domain\Reception\Enums\ConflictResolution;
use App\Domain\Reception\Enums\OfflineEventStatus;
use App\Domain\Reception\Enums\OfflineEventType;
use Carbon\CarbonImmutable;
use Database\Factories\Tenant\OfflineEventFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One replayed client event (SCHEMA §3.3 `offline_events`). Exactly once per (device, client_event_id): a re-sent
 * event whose row is accepted|conflict|rejected returns the stored server_result verbatim (OFFLINE §7.2).
 *
 * @property int $id
 * @property int $reception_device_id
 * @property string $client_event_id
 * @property int $sequence_no
 * @property OfflineEventType $type
 * @property string|null $depends_on
 * @property int|null $actor_user_id
 * @property int|null $session_instance_id
 * @property int|null $serial_block_id
 * @property array<string, mixed> $payload
 * @property OfflineEventStatus $status
 * @property ConflictReason|null $conflict_reason
 * @property array<string, mixed>|null $server_result
 * @property ConflictResolution|null $resolution
 * @property array<string, mixed>|null $resolution_params
 * @property int|null $resolved_by_user_id
 * @property int $attempts
 * @property CarbonImmutable $client_occurred_at
 * @property CarbonImmutable $received_at
 * @property CarbonImmutable|null $processed_at
 * @property-read ReceptionDevice $device
 */
final class OfflineEvent extends TenantModel
{
    /** @use HasFactory<OfflineEventFactory> */
    use HasFactory;

    public const CREATED_AT = 'received_at';

    public const UPDATED_AT = null;

    protected static string $factory = OfflineEventFactory::class;

    protected $table = 'offline_events';

    protected $fillable = [
        'reception_device_id', 'client_event_id', 'sequence_no', 'type', 'depends_on', 'actor_user_id', 'session_instance_id',
        'serial_block_id', 'payload', 'status', 'conflict_reason', 'server_result', 'resolution', 'resolution_params',
        'resolved_by_user_id', 'attempts', 'client_occurred_at', 'received_at', 'processed_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'sequence_no' => 'integer',
            'type' => OfflineEventType::class,
            'payload' => 'array',
            'status' => OfflineEventStatus::class,
            'conflict_reason' => ConflictReason::class,
            'server_result' => 'array',
            'resolution' => ConflictResolution::class,
            'resolution_params' => 'array',
            'attempts' => 'integer',
            'client_occurred_at' => 'immutable_datetime',
            'received_at' => 'immutable_datetime',
            'processed_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<ReceptionDevice, $this> */
    public function device(): BelongsTo
    {
        return $this->belongsTo(ReceptionDevice::class, 'reception_device_id');
    }

    /** @return BelongsTo<User, $this> */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /**
     * The wire shape of one result (OFFLINE §7.1).
     *
     * @return array<string, mixed>
     */
    public function toResult(): array
    {
        return array_filter([
            'client_event_id' => $this->client_event_id,
            'status' => $this->status->value,
            'conflict_reason' => $this->conflict_reason?->value,
            'server_result' => $this->server_result ?? [],
        ], fn ($v) => $v !== null);
    }
}
