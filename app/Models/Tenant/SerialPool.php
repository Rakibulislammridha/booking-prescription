<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use App\Domain\Serials\Enums\SerialPool as Pool;
use Database\Factories\Tenant\SerialPoolFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One of the three disjoint number ranges of a session instance — the FOR UPDATE lock target of allocation (SCHEMA §5.1).
 * next_number only ever increases; returned numbers come back through released serial_blocks rows (the free-list).
 *
 * @property int $id
 * @property int $session_instance_id
 * @property Pool $pool
 * @property int $range_start
 * @property int $range_end
 * @property int $next_number
 * @property int $issued_count
 * @property int $lock_version
 * @property-read SessionInstance $sessionInstance
 */
final class SerialPool extends TenantModel
{
    /** @use HasFactory<SerialPoolFactory> */
    use HasFactory;

    protected static string $factory = SerialPoolFactory::class;

    protected $table = 'serial_pools';

    protected $fillable = ['session_instance_id', 'pool', 'range_start', 'range_end', 'next_number', 'issued_count', 'lock_version'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'pool' => Pool::class,
            'range_start' => 'integer',
            'range_end' => 'integer',
            'next_number' => 'integer',
            'issued_count' => 'integer',
            'lock_version' => 'integer',
        ];
    }

    /** @return BelongsTo<SessionInstance, $this> */
    public function sessionInstance(): BelongsTo
    {
        return $this->belongsTo(SessionInstance::class);
    }

    /** @return HasMany<SerialBlock, $this> */
    public function blocks(): HasMany
    {
        return $this->hasMany(SerialBlock::class);
    }

    public function remaining(): int
    {
        return max(0, $this->range_end - $this->next_number + 1);
    }

    public function isExhausted(): bool
    {
        return $this->next_number > $this->range_end;
    }

    /** True while nothing has been issued and the range can still be rewritten (ChangePoolSplit). */
    public function isUntouched(): bool
    {
        return $this->next_number === $this->range_start;
    }
}
