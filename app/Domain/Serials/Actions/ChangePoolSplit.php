<?php

declare(strict_types=1);

namespace App\Domain\Serials\Actions;

use App\Domain\Serials\Enums\SerialEventType;
use App\Domain\Serials\Events\SessionCapacityExtended;
use App\Domain\Serials\Exceptions\IllegalSessionState;
use App\Domain\Serials\Exceptions\SplitLocked;
use App\Domain\Serials\Services\CapacityService;
use App\Domain\Serials\Services\CountsRecalculator;
use App\Domain\Serials\Services\PoolLayout;
use App\Domain\Serials\Services\SerialEventWriter;
use App\Domain\Serials\Services\SessionLocks;
use App\Domain\Shared\Actor;
use App\Models\Tenant\SerialBlock;
use App\Models\Tenant\SessionInstance;
use Illuminate\Support\Facades\DB;

/**
 * Untouched instance only (SERIAL_ENGINE §3.6.2): rewrites the three ranges for C', O' (buffer kept). Legal only while
 * every pool has next_number = range_start and no serial_blocks exist; otherwise SplitLocked (409). Permission
 * serials.split.adjust (Doctor / Super Admin) is checked by the policy upstream. Event `split_changed`.
 */
final class ChangePoolSplit
{
    public function __construct(private readonly SerialEventWriter $events, private readonly CapacityService $capacity) {}

    public function handle(SessionInstance $instance, int $counterQuota, int $onlineQuota, Actor $actor, ?int $bufferQuota = null): SessionInstance
    {
        if ($counterQuota < 0 || $onlineQuota < 0 || ($bufferQuota !== null && $bufferQuota < 0)) {
            throw new IllegalSessionState($instance, 'set a negative quota');
        }

        $session = DB::transaction(function () use ($instance, $counterQuota, $onlineQuota, $bufferQuota, $actor): SessionInstance {
            $pools = SessionLocks::lockPools($instance->id);
            $session = SessionLocks::lockSession($instance->id);

            if (! $session->acceptsSerials()) {
                throw new IllegalSessionState($session, 'change the split');
            }

            foreach ($pools as $pool) {
                if (! $pool->isUntouched()) {
                    throw new SplitLocked($session->id, "The {$pool->pool->value} pool has issued numbers.");
                }
            }

            if (SerialBlock::query()->where('session_instance_id', $session->id)->exists()) {
                throw new SplitLocked($session->id, 'Blocks have been leased.');
            }

            $buffer = $bufferQuota ?? $session->buffer_quota;
            $before = ['counter' => $session->counter_quota, 'online' => $session->online_quota, 'buffer' => $session->buffer_quota];

            // Two passes so the exclusion constraint never sees a transient overlap: collapse to empty ranges, then rewrite.
            foreach ($pools as $pool) {
                DB::table('serial_pools')->where('id', $pool->id)->update(['range_start' => 1, 'range_end' => 0, 'next_number' => 1]);
            }

            foreach (PoolLayout::ranges($counterQuota, $onlineQuota, $buffer) as $name => $range) {
                DB::table('serial_pools')->where('id', $pools[$name]->id)->update($range + ['updated_at' => now()]);
            }

            $session->forceFill([
                'counter_quota' => $counterQuota, 'online_quota' => $onlineQuota, 'buffer_quota' => $buffer,
                'max_serials' => $counterQuota + $onlineQuota + $buffer,
            ])->save();

            $this->events->write($session, null, SerialEventType::SplitChanged, ['before' => $before, 'after' => ['counter' => $counterQuota, 'online' => $onlineQuota, 'buffer' => $buffer]], $actor);
            CountsRecalculator::bumpVersion($session->id);
            SessionCapacityExtended::dispatch($session, $actor, ['split' => ['counter' => $counterQuota, 'online' => $onlineQuota, 'buffer' => $buffer]]);

            return $session;
        }, attempts: 3);

        $this->capacity->forget($session->public_id);

        return $session;
    }
}
