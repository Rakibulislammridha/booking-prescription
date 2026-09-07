<?php

declare(strict_types=1);

namespace App\Domain\Reception\Listeners;

use App\Domain\Serials\Actions\ReleaseBlock;
use App\Domain\Serials\Enums\BlockStatus;
use App\Domain\Serials\Events\SessionClosed;
use App\Domain\Shared\Actor;
use App\Models\Tenant\SerialBlock;

/**
 * ARCHITECTURE §5.4: CloseSession already releases every active block inside its transaction (SERIAL_ENGINE §2.5);
 * this after-commit listener is the safety net for a block leased in the race window. Idempotent.
 */
final class ReleaseBlocksOnSessionClosed
{
    public function __construct(private readonly ReleaseBlock $release) {}

    public function handle(SessionClosed $event): void
    {
        $blocks = SerialBlock::query()->where('session_instance_id', $event->sessionInstanceId)->where('status', BlockStatus::Active->value)->get();

        foreach ($blocks as $block) {
            $this->release->handle($block, Actor::system(), 'session_closed');
        }
    }
}
