<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use App\Domain\Serials\Enums\BlockStatus;
use Carbon\CarbonImmutable;
use Database\Factories\Tenant\SerialBlockFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A contiguous range carved out of a pool with its own cursor: a device lease (reception_device_id set) or a desk-owned
 * released range (reception_device_id NULL) that is the reusable free-list (SCHEMA §3.3, OFFLINE §4).
 *
 * @property int $id
 * @property string $public_id
 * @property int $session_instance_id
 * @property int $serial_pool_id
 * @property int|null $reception_device_id
 * @property int $range_start
 * @property int $range_end
 * @property int $next_number
 * @property BlockStatus $status
 * @property CarbonImmutable $leased_at
 * @property int|null $leased_by_user_id
 * @property CarbonImmutable|null $expires_at
 * @property CarbonImmutable|null $released_at
 * @property CarbonImmutable|null $revoked_at
 * @property int $returned_count
 * @property-read SessionInstance $sessionInstance
 * @property-read SerialPool $pool
 */
final class SerialBlock extends TenantModel
{
    /** @use HasFactory<SerialBlockFactory> */
    use HasFactory;

    protected static string $factory = SerialBlockFactory::class;

    protected static bool $publicId = true;

    protected $table = 'serial_blocks';

    protected $fillable = [
        'public_id', 'session_instance_id', 'serial_pool_id', 'reception_device_id', 'range_start', 'range_end', 'next_number', 'status',
        'leased_at', 'leased_by_user_id', 'expires_at', 'released_at', 'revoked_at', 'returned_count',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'range_start' => 'integer',
            'range_end' => 'integer',
            'next_number' => 'integer',
            'status' => BlockStatus::class,
            'leased_at' => 'immutable_datetime',
            'expires_at' => 'immutable_datetime',
            'released_at' => 'immutable_datetime',
            'revoked_at' => 'immutable_datetime',
            'returned_count' => 'integer',
        ];
    }

    /** @return BelongsTo<SessionInstance, $this> */
    public function sessionInstance(): BelongsTo
    {
        return $this->belongsTo(SessionInstance::class);
    }

    /** @return BelongsTo<SerialPool, $this> */
    public function pool(): BelongsTo
    {
        return $this->belongsTo(SerialPool::class, 'serial_pool_id');
    }

    /** @return HasMany<Serial, $this> */
    public function serials(): HasMany
    {
        return $this->hasMany(Serial::class);
    }

    /** @param  Builder<SerialBlock>  $query */
    public function scopeActive(Builder $query): void
    {
        $query->where('status', BlockStatus::Active->value);
    }

    public function remaining(): int
    {
        return max(0, $this->range_end - $this->next_number + 1);
    }

    public function isRevoked(): bool
    {
        return $this->status === BlockStatus::Released && $this->revoked_at !== null;
    }

    public function isDeskOwned(): bool
    {
        return $this->reception_device_id === null;
    }
}
