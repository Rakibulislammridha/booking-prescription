<?php

declare(strict_types=1);

namespace App\Domain\Serials\Actions;

use App\Domain\Serials\Enums\BlockStatus;
use App\Domain\Serials\Enums\SerialEventType;
use App\Domain\Serials\Enums\SerialPool;
use App\Domain\Serials\Events\SessionCapacityExtended;
use App\Domain\Serials\Exceptions\IllegalSessionState;
use App\Domain\Serials\Exceptions\SplitLocked;
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
 * Live instance, one-way online → counter (SERIAL_ENGINE §3.6.3): with k = null the whole unissued online remainder
 * is released and online booking closes; with k the top k numbers of the online range are released. The released
 * range becomes a desk-owned serial_blocks row (reception_device_id NULL, serial_pool_id = the online pool,
 * status released) that the counter drains before its own cursor. The reverse is not provided.
 */
final class ReleaseOnlineToCounter
{
    public function __construct(private readonly SerialEventWriter $events, private readonly CapacityService $capacity) {}

    /** @return array{session: SessionInstance, released_block: SerialBlock|null, range: array{0: int, 1: int}|null} */
    public function handle(SessionInstance $instance, ?int $count, Actor $actor, ?string $reason = null): array
    {
        $result = DB::transaction(function () use ($instance, $count, $actor, $reason): array {
            $pools = SessionLocks::lockPools($instance->id);
            $session = SessionLocks::lockSession($instance->id);

            if (! $session->acceptsSerials()) {
                throw new IllegalSessionState($session, 'release online numbers');
            }

            $online = $pools[SerialPool::Online->value];
            $unissued = $online->range_end - $online->next_number + 1;

            if ($unissued <= 0) {
                return ['session' => $session, 'released_block' => null, 'range' => null];
            }

            $k = $count === null ? $unissued : $count;

            if ($k < 1 || $online->next_number > $online->range_end - $k + 1) {
                throw new SplitLocked($session->id, "Only {$unissued} online number(s) are unissued.");
            }

            $oldEnd = $online->range_end;
            $newEnd = $oldEnd - $k;

            DB::table('serial_pools')->where('id', $online->id)->update(['range_end' => $newEnd, 'next_number' => min($online->next_number, $newEnd + 1), 'updated_at' => now()]);

            $block = new SerialBlock;
            $block->forceFill([
                'public_id' => (string) Str::ulid(),
                'session_instance_id' => $session->id,
                'serial_pool_id' => $online->id,
                'reception_device_id' => null,
                'range_start' => $newEnd + 1,
                'range_end' => $oldEnd,
                'next_number' => $newEnd + 1,
                'status' => BlockStatus::Released,
                'leased_at' => now(),
                'leased_by_user_id' => $actor->userId,
                'released_at' => now(),
                'returned_count' => $k,
            ])->save();

            $session->forceFill(['online_quota' => $session->online_quota - $k, 'counter_quota' => $session->counter_quota + $k])->save();

            $this->events->write($session, null, SerialEventType::OnlineReleased, ['range' => [$newEnd + 1, $oldEnd], 'released_block_id' => $block->id, 'reason' => $reason], $actor, ['reason' => $reason]);
            CountsRecalculator::bumpVersion($session->id);
            SessionCapacityExtended::dispatch($session, $actor, ['online_released' => [$newEnd + 1, $oldEnd]]);

            return ['session' => $session, 'released_block' => $block, 'range' => [$newEnd + 1, $oldEnd]];
        }, attempts: 3);

        $this->capacity->forget($result['session']->public_id);

        return $result;
    }
}
