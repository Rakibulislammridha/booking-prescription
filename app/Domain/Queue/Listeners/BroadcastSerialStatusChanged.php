<?php

declare(strict_types=1);

namespace App\Domain\Queue\Listeners;

use App\Domain\Queue\Services\QueueBroadcaster;
use App\Domain\Serials\Events\SerialStatusChanged;
use App\Models\Tenant\Serial;
use App\Models\Tenant\SessionInstance;
use App\Tenancy\Facades\Tenancy;

/** `serial.status_changed` on queue + reception (REALTIME.md §3.1). */
final class BroadcastSerialStatusChanged
{
    public function __construct(private readonly QueueBroadcaster $broadcaster) {}

    public function handle(SerialStatusChanged $event): void
    {
        if (! Tenancy::check()) {
            return;
        }

        $serial = Serial::query()->find($event->serialId);
        $session = SessionInstance::query()->with(['doctor', 'branch'])->find($event->sessionInstanceId);

        if ($serial === null || $session === null) {
            return;
        }

        $this->broadcaster->serialStatusChanged($session, $serial, $event->from, $event->to);
    }
}
