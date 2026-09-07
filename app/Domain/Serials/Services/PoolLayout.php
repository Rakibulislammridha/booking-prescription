<?php

declare(strict_types=1);

namespace App\Domain\Serials\Services;

use App\Domain\Serials\Enums\SerialPool;
use Illuminate\Support\Facades\DB;

/**
 * Pool layout of SERIAL_ENGINE §3.1: counter [1, C], online [C+1, C+O], buffer [C+O+1, C+O+B].
 * A zero quota is the empty range range_end = range_start - 1 (next_number = range_start) so lookups never miss.
 */
final class PoolLayout
{
    /**
     * @return array<string, array{range_start: int, range_end: int, next_number: int}> keyed by pool value in the
     *                                                                                  lock order (buffer, counter, online)
     */
    public static function ranges(int $counterQuota, int $onlineQuota, int $bufferQuota): array
    {
        $counter = ['range_start' => 1, 'range_end' => $counterQuota, 'next_number' => 1];
        $online = ['range_start' => $counterQuota + 1, 'range_end' => $counterQuota + $onlineQuota, 'next_number' => $counterQuota + 1];
        $buffer = ['range_start' => $counterQuota + $onlineQuota + 1, 'range_end' => $counterQuota + $onlineQuota + $bufferQuota, 'next_number' => $counterQuota + $onlineQuota + 1];

        return [
            SerialPool::Buffer->value => $buffer,
            SerialPool::Counter->value => $counter,
            SerialPool::Online->value => $online,
        ];
    }

    /** Inserts the three pool rows for a freshly created instance (same transaction as the instance insert). */
    public static function createFor(int $sessionInstanceId, int $counterQuota, int $onlineQuota, int $bufferQuota): void
    {
        $now = now();
        $rows = [];

        foreach (self::ranges($counterQuota, $onlineQuota, $bufferQuota) as $pool => $range) {
            $rows[] = $range + ['session_instance_id' => $sessionInstanceId, 'pool' => $pool, 'issued_count' => 0, 'lock_version' => 0, 'created_at' => $now, 'updated_at' => $now];
        }

        DB::table('serial_pools')->insert($rows);
    }
}
