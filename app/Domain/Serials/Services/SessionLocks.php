<?php

declare(strict_types=1);

namespace App\Domain\Serials\Services;

use App\Domain\Serials\Enums\SerialPool as Pool;
use App\Models\Tenant\SerialPool;
use App\Models\Tenant\SessionInstance;
use Illuminate\Database\Eloquent\Collection;

/**
 * The lock discipline of SERIAL_ENGINE §4.2 / SCHEMA §5.1: owner rows first (released blocks, then pools by name
 * ascending — buffer, counter, online), then the session_instances row, then serials. Every session-level action
 * that changes status or pool ranges takes its rows in this order so it never deadlocks with an allocation.
 */
final class SessionLocks
{
    /**
     * All three pools FOR UPDATE in lock order, keyed by pool value.
     *
     * @return array<string, SerialPool>
     */
    public static function lockPools(int $sessionInstanceId): array
    {
        /** @var Collection<int, SerialPool> $pools */
        $pools = SerialPool::query()
            ->where('session_instance_id', $sessionInstanceId)
            ->orderBy('pool')
            ->lockForUpdate()
            ->get();

        $byPool = [];

        foreach ($pools as $pool) {
            $byPool[$pool->pool->value] = $pool;
        }

        return $byPool;
    }

    /** One pool FOR UPDATE. */
    public static function lockPool(int $sessionInstanceId, Pool $pool): SerialPool
    {
        /** @var SerialPool $row */
        $row = SerialPool::query()->where('session_instance_id', $sessionInstanceId)->where('pool', $pool->value)->lockForUpdate()->firstOrFail();

        return $row;
    }

    /** The session row FOR UPDATE, fresh. */
    public static function lockSession(int $sessionInstanceId): SessionInstance
    {
        /** @var SessionInstance $session */
        $session = SessionInstance::query()->whereKey($sessionInstanceId)->lockForUpdate()->firstOrFail();

        return $session;
    }
}
