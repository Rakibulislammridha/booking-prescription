<?php

declare(strict_types=1);

namespace App\Domain\Reception\Services;

use App\Domain\Serials\Enums\BlockStatus;
use App\Models\Tenant\SerialBlock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * OFFLINE §8.2 (number free on a released/revoked row): take exactly `number` out of the released row for the device
 * that issued it offline. The row is shrunk to [range_start..n-1], a new released row [n+1..range_end] carries the
 * tail, and a one-number ACTIVE block [n..n] owned by the device is created so the engine (AllocateFromBlock) issues
 * the number through its normal owner-row lock — all three ranges are disjoint, so `serial_blocks_range_excl` holds
 * and the original row keeps its revoked_at (an active row may never carry one). Must run inside a transaction.
 */
final class BlockCarver
{
    /** @return SerialBlock|null the one-number active block, or null when the number is no longer on this row */
    public function carve(SerialBlock $block, int $number, int $deviceId, ?int $actorUserId): ?SerialBlock
    {
        /** @var SerialBlock $locked */
        $locked = SerialBlock::query()->whereKey($block->id)->lockForUpdate()->firstOrFail();

        if ($locked->status === BlockStatus::Active || $number < $locked->next_number || $number > $locked->range_end) {
            return null;
        }

        $start = $locked->range_start;
        $end = $locked->range_end;
        $cursor = $locked->next_number;

        // 1. shrink the original to the part below n (may become empty: range_end = range_start - 1)
        $newEnd = $number - 1;
        $newStart = $start;
        $newCursor = min($cursor, $newEnd + 1);

        if ($newEnd < $newStart) {
            $newStart = $number;      // empty range [n, n-1]; next_number = range_start keeps the CHECK
            $newEnd = $number - 1;
            $newCursor = $number;
        }

        DB::update(
            "UPDATE serial_blocks SET range_start = :start, range_end = :end, next_number = :next, status = CASE WHEN :next2 > :end2 THEN 'exhausted' ELSE 'released' END, updated_at = now() WHERE id = :id",
            ['start' => $newStart, 'end' => $newEnd, 'next' => $newCursor, 'next2' => $newCursor, 'end2' => $newEnd, 'id' => $locked->id],
        );

        // 2. the tail above n stays a released free-list row (same pool, same revoked marker)
        if ($number + 1 <= $end) {
            $tail = new SerialBlock;
            $tail->forceFill([
                'public_id' => (string) Str::ulid(),
                'session_instance_id' => $locked->session_instance_id,
                'serial_pool_id' => $locked->serial_pool_id,
                'reception_device_id' => null,
                'range_start' => $number + 1,
                'range_end' => $end,
                'next_number' => max($cursor, $number + 1),
                'status' => BlockStatus::Released,
                'leased_at' => $locked->leased_at,
                'leased_by_user_id' => $locked->leased_by_user_id,
                'expires_at' => $locked->expires_at,
                'released_at' => $locked->released_at ?? now(),
                'revoked_at' => $locked->revoked_at,
                'returned_count' => max(0, $end - max($cursor, $number + 1) + 1),
            ])->save();
        }

        // 3. the number itself: a one-number active lease for the device that printed the slip
        $tiny = new SerialBlock;
        $tiny->forceFill([
            'public_id' => (string) Str::ulid(),
            'session_instance_id' => $locked->session_instance_id,
            'serial_pool_id' => $locked->serial_pool_id,
            'reception_device_id' => $deviceId,
            'range_start' => $number,
            'range_end' => $number,
            'next_number' => $number,
            'status' => BlockStatus::Active,
            'leased_at' => now(),
            'leased_by_user_id' => $actorUserId,
            'expires_at' => $locked->expires_at,
            'returned_count' => 0,
        ])->save();

        return $tiny;
    }
}
