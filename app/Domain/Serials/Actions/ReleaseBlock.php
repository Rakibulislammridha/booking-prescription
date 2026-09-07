<?php

declare(strict_types=1);

namespace App\Domain\Serials\Actions;

use App\Domain\Serials\Enums\BlockStatus;
use App\Domain\Serials\Enums\SerialEventType;
use App\Domain\Serials\Events\BlockReleased;
use App\Domain\Serials\Services\CapacityService;
use App\Domain\Serials\Services\CountsRecalculator;
use App\Domain\Serials\Services\SerialEventWriter;
use App\Domain\Shared\Actor;
use App\Models\Tenant\SerialBlock;
use App\Models\Tenant\SessionInstance;
use Illuminate\Support\Facades\DB;

/**
 * OFFLINE §4.4: lock the block; status = released (exhausted when nothing remains), released_at, returned_count =
 * range_end - next_number + 1. Unissued numbers stay on this row as the free-list and are reused in the same session.
 */
final class ReleaseBlock
{
    public function __construct(private readonly SerialEventWriter $events, private readonly CapacityService $capacity) {}

    public function handle(SerialBlock $block, Actor $actor, ?string $reason = null, bool $revoke = false): SerialBlock
    {
        $released = DB::transaction(function () use ($block, $actor, $reason, $revoke): SerialBlock {
            /** @var SerialBlock $locked */
            $locked = SerialBlock::query()->whereKey($block->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== BlockStatus::Active) {
                if ($revoke && $locked->revoked_at === null && $locked->status === BlockStatus::Released) {
                    $locked->forceFill(['revoked_at' => now()])->save();
                }

                return $locked;   // idempotent
            }

            $returned = max(0, $locked->range_end - $locked->next_number + 1);

            $locked->forceFill([
                'status' => $returned > 0 ? BlockStatus::Released : BlockStatus::Exhausted,
                'released_at' => now(),
                'revoked_at' => $revoke ? now() : null,
                'returned_count' => $returned,
            ])->save();

            /** @var SessionInstance $session */
            $session = SessionInstance::query()->findOrFail($locked->session_instance_id);
            $type = $revoke ? SerialEventType::BlockRevoked : SerialEventType::BlockReleased;
            $meta = ['block_id' => $locked->id, 'range' => [$locked->range_start, $locked->range_end], 'returned' => $returned, 'reason' => $reason];

            $this->events->write($session, null, $type, $meta, $actor, ['reason' => $reason]);
            CountsRecalculator::bumpVersion($session->id);
            BlockReleased::dispatch($session, $actor, $meta + ['revoked' => $revoke]);

            return $locked;
        }, attempts: 3);

        $this->capacity->forget((string) SessionInstance::query()->whereKey($released->session_instance_id)->value('public_id'));

        return $released;
    }
}
