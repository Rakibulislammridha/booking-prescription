<?php

declare(strict_types=1);

namespace App\Domain\Serials\Actions;

use App\Domain\Clinic\Services\Settings;
use App\Domain\Serials\Enums\BlockStatus;
use App\Domain\Serials\Enums\SerialEventType;
use App\Domain\Serials\Enums\SerialPool;
use App\Domain\Serials\Events\BlockLeased;
use App\Domain\Serials\Exceptions\BlockLimitReached;
use App\Domain\Serials\Exceptions\PoolExhausted;
use App\Domain\Serials\Exceptions\SessionNotOpen;
use App\Domain\Serials\Services\CapacityService;
use App\Domain\Serials\Services\CountsRecalculator;
use App\Domain\Serials\Services\SerialEventWriter;
use App\Domain\Serials\Services\SessionLocks;
use App\Domain\Shared\Actor;
use App\Models\Tenant\SerialBlock;
use App\Models\Tenant\SessionInstance;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Block lease (OFFLINE §4.1, SCHEMA §5.1.3): size = min(requested, device block_size, serial.default_block_size, 30,
 * remaining). Lock order as AllocateSerial: a released row first (FOR UPDATE SKIP LOCKED, lowest range_start),
 * else the counter pool; the device may hold at most serial.max_active_blocks_per_device active blocks per session,
 * counted under the same lock. serial_pool_id is always the counter pool. The Reception module (R) wraps this with
 * its ReceptionDevice model; here the device is its id + block_size.
 */
final class LeaseBlock
{
    public const HARD_MAX = 30;

    public function __construct(
        private readonly Settings $settings,
        private readonly SerialEventWriter $events,
        private readonly CapacityService $capacity,
    ) {}

    /**
     * @throws PoolExhausted
     * @throws BlockLimitReached
     * @throws SessionNotOpen
     */
    public function handle(SessionInstance $instance, int $deviceId, int $requested, Actor $actor, ?int $deviceBlockSize = null): SerialBlock
    {
        $block = DB::transaction(function () use ($instance, $deviceId, $requested, $actor, $deviceBlockSize): SerialBlock {
            // (a) prefer a desk-owned/released range so returned numbers go out first
            $released = DB::selectOne(
                "SELECT id, next_number, range_end FROM serial_blocks WHERE session_instance_id = :sid AND status = 'released' AND next_number <= range_end ORDER BY range_start FOR UPDATE SKIP LOCKED LIMIT 1",
                ['sid' => $instance->id],
            );

            // (b) the counter pool — always locked: it serialises every lease and desk allocation for the session
            $counter = SessionLocks::lockPool($instance->id, SerialPool::Counter);

            /** @var SessionInstance $session */
            $session = SessionInstance::query()->findOrFail($instance->id);

            if (! $session->acceptsSerials()) {
                throw new SessionNotOpen($session);
            }

            $maxActive = max(1, (int) $this->settings->get('serial.max_active_blocks_per_device'));
            $active = SerialBlock::query()->where('session_instance_id', $session->id)->where('reception_device_id', $deviceId)->where('status', BlockStatus::Active->value)->count();

            if ($active >= $maxActive) {
                throw new BlockLimitReached($active);
            }

            // size = min(requested, device block_size (settings serial.default_block_size when the device has none), 30, remaining)
            $size = min($requested, $deviceBlockSize ?? (int) $this->settings->get('serial.default_block_size'), self::HARD_MAX);

            $size = max(1, $size);

            if ($released !== null) {
                $start = (int) $released->next_number;
                $end = min($start + $size - 1, (int) $released->range_end);
                DB::update(
                    "UPDATE serial_blocks SET next_number = :next, status = CASE WHEN :next2 > range_end THEN 'exhausted' ELSE 'released' END, updated_at = now() WHERE id = :id",
                    ['next' => $end + 1, 'next2' => $end + 1, 'id' => (int) $released->id],
                );
                // Shrink the released row so the two rows stay disjoint under serial_blocks_range_excl.
                DB::update('UPDATE serial_blocks SET range_start = :start WHERE id = :id AND range_start <= :end', ['start' => $end + 1, 'id' => (int) $released->id, 'end' => $end]);
                $this->fixEmptyRow((int) $released->id);
            } else {
                $remaining = $counter->remaining();

                if ($remaining <= 0) {
                    throw new PoolExhausted(SerialPool::Counter, $session->id, $this->capacity->remainingFor([$session->id])[$session->id] ?? []);
                }

                $size = min($size, $remaining);
                $start = $counter->next_number;
                $end = $start + $size - 1;
                DB::update('UPDATE serial_pools SET next_number = :next, issued_count = issued_count + :size, lock_version = lock_version + 1, updated_at = now() WHERE id = :id', ['next' => $end + 1, 'size' => $size, 'id' => $counter->id]);
            }

            $block = new SerialBlock;
            $block->forceFill([
                'public_id' => (string) Str::ulid(),
                'session_instance_id' => $session->id,
                'serial_pool_id' => $counter->id,
                'reception_device_id' => $deviceId,
                'range_start' => $start,
                'range_end' => $end,
                'next_number' => $start,
                'status' => BlockStatus::Active,
                'leased_at' => now(),
                'leased_by_user_id' => $actor->userId,
                'expires_at' => $session->planned_end_at->addHours(2),
                'returned_count' => 0,
            ])->save();

            $this->events->write($session, null, SerialEventType::BlockLeased, ['block_id' => $block->id, 'range' => [$start, $end], 'device_id' => $deviceId], $actor);
            CountsRecalculator::bumpVersion($session->id);
            BlockLeased::dispatch($session, $actor, ['block_id' => $block->id, 'range' => [$start, $end], 'device_id' => $deviceId]);

            return $block;
        }, attempts: 3);

        $this->capacity->forget((string) SessionInstance::query()->whereKey($block->session_instance_id)->value('public_id'));

        return $block;
    }

    /** A released row carved down to nothing keeps a legal empty range (range_end = range_start - 1, next = start). */
    private function fixEmptyRow(int $id): void
    {
        DB::update('UPDATE serial_blocks SET next_number = range_start WHERE id = :id AND next_number > range_end + 1', ['id' => $id]);
    }
}
