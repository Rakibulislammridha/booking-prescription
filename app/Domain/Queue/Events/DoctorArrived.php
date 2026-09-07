<?php

declare(strict_types=1);

namespace App\Domain\Queue\Events;

use Illuminate\Contracts\Broadcasting\ShouldBroadcast;

/** `doctor.arrived` on queue + reception + display (REALTIME.md §3.1), queued on `critical`. */
final class DoctorArrived extends WireEvent implements ShouldBroadcast
{
    public string $broadcastQueue = 'critical';

    public bool $afterCommit = true;

    public function broadcastAs(): string
    {
        return 'doctor.arrived';
    }
}
