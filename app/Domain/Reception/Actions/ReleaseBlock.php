<?php

declare(strict_types=1);

namespace App\Domain\Reception\Actions;

use App\Domain\Reception\Exceptions\BlockNotOwned;
use App\Domain\Serials\Actions\ReleaseBlock as EngineReleaseBlock;
use App\Domain\Shared\Actor;
use App\Models\Tenant\ReceptionDevice;
use App\Models\Tenant\SerialBlock;

/** OFFLINE §4.4: a device returns its own block (session close on the client, logout); the remainder is the free-list. */
final class ReleaseBlock
{
    public function __construct(private readonly EngineReleaseBlock $engine) {}

    /** @return array{block: SerialBlock, released_unused: int} */
    public function handle(ReceptionDevice $device, SerialBlock $block, Actor $actor, ?string $reason = null): array
    {
        if ($block->reception_device_id !== $device->id) {
            throw new BlockNotOwned;
        }

        $before = $block->remaining();
        $released = $this->engine->handle($block, $actor, $reason ?? 'device_release');

        return ['block' => $released, 'released_unused' => $released->status->value === 'active' ? 0 : ($released->returned_count > 0 ? $released->returned_count : $before)];
    }
}
