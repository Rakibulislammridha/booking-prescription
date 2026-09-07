<?php

declare(strict_types=1);

namespace App\Domain\Queue\Events;

use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Broadcasting\ShouldRescue;

/**
 * Session cancelled, with the alternatives the patient can rebook onto.
 * ShouldBroadcastNow + ShouldRescue: an unreachable Reverb must never fail the HTTP request that called next — the
 * poll fallback carries the state and the swallowed broadcast is logged (REALTIME.md §3.2).
 */
final class SessionCancelled extends WireEvent implements ShouldBroadcastNow, ShouldRescue
{
    public function broadcastAs(): string
    {
        return 'session.cancelled';
    }
}
