<?php

declare(strict_types=1);

namespace App\Domain\Queue\Events;

use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

/**
 * `serial.status_changed` on queue + reception (REALTIME.md §3.1): the transition plus the session counts, queued on
 * `critical`. Not the Serials domain event of the same short name — this is the wire class.
 */
final class SerialStatusChanged extends WireEvent implements ShouldBroadcast
{
    public string $broadcastQueue = 'critical';

    public bool $afterCommit = true;

    public function broadcastAs(): string
    {
        return 'serial.status_changed';
    }
}
