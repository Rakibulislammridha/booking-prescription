<?php

declare(strict_types=1);

namespace App\Domain\Queue\Events;

use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;

/**
 * Public payload of a call: queue + reception + doctor + display. Never carries a name.
 * ShouldBroadcastNow + ShouldRescue: an unreachable Reverb must never fail the HTTP request that called next — the
 * poll fallback carries the state and the swallowed broadcast is logged (REALTIME.md §3.2).
 */
final class SerialCalled extends WireEvent implements ShouldBroadcastNow, ShouldRescue
{
    public function broadcastAs(): string
    {
        return 'serial.called';
    }
}
