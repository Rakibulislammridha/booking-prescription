<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use App\Domain\Serials\Enums\CancelReason;
use App\Domain\Serials\Enums\SerialPool;
use App\Domain\Serials\Enums\SerialPriority;
use App\Domain\Serials\Enums\SerialSource;
use App\Domain\Serials\Enums\SerialStatus;
use Carbon\CarbonImmutable;
use Database\Factories\Tenant\SerialFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One issued token in a session instance. `number` is the identity (unique per session, never changes);
 * `position` is the calling order (SCHEMA §3.3, SERIAL_ENGINE §1). Audit rows are written explicitly by the
 * engine (allocation, transitions, reorders, transfers) rather than through the model hooks, because the
 * allocation path inserts with the query builder under the pool lock.
 *
 * @property int $id
 * @property string $public_id
 * @property int $session_instance_id
 * @property int $number
 * @property string $display_code
 * @property int $position
 * @property SerialPool $pool
 * @property SerialStatus $status
 * @property SerialPriority $priority
 * @property SerialSource $source
 * @property int|null $appointment_id
 * @property int|null $patient_id
 * @property int|null $serial_block_id
 * @property int|null $reception_device_id
 * @property string|null $client_event_id
 * @property int|null $transferred_from_serial_id
 * @property int|null $transferred_to_serial_id
 * @property int|null $postponed_to_serial_id
 * @property int|null $issued_by_user_id
 * @property CarbonImmutable|null $slot_start_at
 * @property CarbonImmutable $booked_at
 * @property CarbonImmutable|null $checked_in_at
 * @property CarbonImmutable|null $called_at
 * @property CarbonImmutable|null $consultation_started_at
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $no_show_at
 * @property CarbonImmutable|null $cancelled_at
 * @property CarbonImmutable|null $postponed_at
 * @property CancelReason|null $cancel_reason_code
 * @property CarbonImmutable|null $reinstated_at
 * @property CarbonImmutable|null $t3_notified_at
 * @property int $passed_count
 * @property int $skip_count
 * @property CarbonImmutable|null $estimated_call_at
 * @property string|null $notes
 * @property-read SessionInstance $sessionInstance
 * @property-read SerialBlock|null $block
 * @property-read Collection<int, SerialEvent> $events
 */
final class Serial extends TenantModel
{
    /** @use HasFactory<SerialFactory> */
    use HasFactory;

    protected static string $factory = SerialFactory::class;

    protected static bool $publicId = true;

    protected $table = 'serials';

    protected $fillable = [
        'public_id', 'session_instance_id', 'number', 'display_code', 'position', 'pool', 'status', 'priority', 'source',
        'appointment_id', 'patient_id', 'serial_block_id', 'reception_device_id', 'client_event_id',
        'transferred_from_serial_id', 'transferred_to_serial_id', 'postponed_to_serial_id', 'issued_by_user_id', 'slot_start_at',
        'booked_at', 'checked_in_at', 'called_at', 'consultation_started_at', 'completed_at', 'no_show_at', 'cancelled_at', 'postponed_at',
        'cancel_reason_code', 'reinstated_at', 't3_notified_at', 'passed_count', 'skip_count', 'estimated_call_at', 'notes',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'position' => 'integer',
            'pool' => SerialPool::class,
            'status' => SerialStatus::class,
            'priority' => SerialPriority::class,
            'source' => SerialSource::class,
            'cancel_reason_code' => CancelReason::class,
            'slot_start_at' => 'immutable_datetime',
            'booked_at' => 'immutable_datetime',
            'checked_in_at' => 'immutable_datetime',
            'called_at' => 'immutable_datetime',
            'consultation_started_at' => 'immutable_datetime',
            'completed_at' => 'immutable_datetime',
            'no_show_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
            'postponed_at' => 'immutable_datetime',
            'reinstated_at' => 'immutable_datetime',
            't3_notified_at' => 'immutable_datetime',
            'passed_count' => 'integer',
            'skip_count' => 'integer',
            'estimated_call_at' => 'immutable_datetime',
        ];
    }

    /** @return BelongsTo<SessionInstance, $this> */
    public function sessionInstance(): BelongsTo
    {
        return $this->belongsTo(SessionInstance::class);
    }

    /** @return BelongsTo<SerialBlock, $this> */
    public function block(): BelongsTo
    {
        return $this->belongsTo(SerialBlock::class, 'serial_block_id');
    }

    /** @return BelongsTo<Serial, $this> */
    public function transferredFrom(): BelongsTo
    {
        return $this->belongsTo(self::class, 'transferred_from_serial_id');
    }

    /** @return BelongsTo<Serial, $this> */
    public function transferredTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'transferred_to_serial_id');
    }

    /** @return BelongsTo<Serial, $this> */
    public function postponedTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'postponed_to_serial_id');
    }

    /** @return BelongsTo<User, $this> */
    public function issuedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by_user_id');
    }

    /** @return HasMany<SerialEvent, $this> */
    public function events(): HasMany
    {
        return $this->hasMany(SerialEvent::class);
    }

    /**
     * booked | checked_in | in_consultation.
     *
     * @param  Builder<Serial>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->whereIn('status', SerialStatus::activeValues());
    }

    /** @param  Builder<Serial>  $query */
    public function scopeQueueOrder(Builder $query): void
    {
        $query->orderBy('position')->orderBy('number');
    }

    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
    }
}
