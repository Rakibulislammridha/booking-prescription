<?php

declare(strict_types=1);

namespace App\Domain\Serials\Actions;

use App\Domain\Serials\Data\AllocationRequest;
use App\Domain\Serials\Enums\BlockStatus;
use App\Domain\Serials\Enums\SerialPool;
use App\Domain\Serials\Enums\SerialSource;
use App\Domain\Serials\Exceptions\BlockNotIssuable;
use App\Models\Tenant\Serial;
use App\Models\Tenant\SerialBlock;

/**
 * Server-side counterpart of offline issuing (OFFLINE §6.3): validates block ownership/state, then delegates to
 * AllocateSerial with the block as the owner row — the server issues *that* number and sets next_number = number + 1.
 * The Reception module's IssueSerialHandler calls this during replay and maps BlockNotIssuable to its conflict taxonomy.
 */
final class AllocateFromBlock
{
    public function __construct(private readonly AllocateSerial $allocate) {}

    public function handle(int $deviceId, SerialBlock $block, int $number, AllocationRequest $r): Serial
    {
        $request = $r->with([
            'sessionInstanceId' => $block->session_instance_id,
            'pool' => SerialPool::Counter,
            'source' => SerialSource::Offline,
            'serialBlockId' => $block->id,
            'receptionDeviceId' => $deviceId,
            'number' => $number,
        ]);

        // A re-sent event for an already issued number is a no-op even if the block has since been released/revoked.
        if ($request->clientEventId !== null) {
            $existing = Serial::query()->where('session_instance_id', $request->sessionInstanceId)->where('client_event_id', $request->clientEventId)->first();

            if ($existing !== null) {
                return $existing;
            }
        }

        if ($block->reception_device_id !== $deviceId) {
            throw new BlockNotIssuable('block_not_owned');
        }

        if ($block->status !== BlockStatus::Active) {
            throw new BlockNotIssuable($block->isRevoked() ? 'block_revoked' : 'block_'.$block->status->value);
        }

        if ($number < $block->next_number || $number > $block->range_end) {
            throw new BlockNotIssuable('number_out_of_block_range', $block->next_number <= $block->range_end ? $block->next_number : null);
        }

        return ($this->allocate)($request);
    }
}
