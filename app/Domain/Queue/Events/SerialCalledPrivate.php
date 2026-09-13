<?php

declare(strict_types=1);

namespace App\Domain\Queue\Events;

use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;

/**
 * The call on the private channels (REALTIME.md §3.1). Dispatched TWICE by QueueBroadcaster::serialCalled(): once
 * to the doctor's channel and the branch display with the patient card, once to the reception channel with the
 * public payload and no card. It carried the card on all three until a compounder — scoped to their own doctor
 * everywhere else — was found holding a live feed of every chamber at the branch through the desk channel.
 *
 * ShouldBroadcastNow + ShouldRescue: an unreachable Reverb must never fail the HTTP request that called next — the
 * poll fallback carries the state and the swallowed broadcast is logged (REALTIME.md §3.2).
 */
final class SerialCalledPrivate extends WireEvent implements ShouldBroadcastNow, ShouldRescue
{
    public function broadcastAs(): string
    {
        return 'serial.called';
    }
}
