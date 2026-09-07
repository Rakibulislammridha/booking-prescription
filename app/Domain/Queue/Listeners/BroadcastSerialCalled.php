<?php

declare(strict_types=1);

namespace App\Domain\Queue\Listeners;

use App\Domain\Queue\Services\QueueBroadcaster;
use App\Domain\Serials\Events\SerialCalled;
use App\Models\Tenant\Serial;
use App\Models\Tenant\SessionInstance;
use App\Tenancy\Facades\Tenancy;

/**
 * Bridges the Serials domain event onto the wire (REALTIME.md §3): `serial.called` on the public queue channel,
 * `serial.called` with the patient card on the private channels, and `call.next` on the doctor + display channels —
 * the "push simultaneously to the doctor's screen and the waiting-room display" of BRIEF §5.F.
 *
 * Registered AFTER InvalidateQueueState so the payload's `version` is the one the freshly written snapshot carries.
 */
final class BroadcastSerialCalled
{
    public function __construct(private readonly QueueBroadcaster $broadcaster) {}

    public function handle(SerialCalled $event): void
    {
        if (! Tenancy::check()) {
            return;
        }

        $serial = Serial::query()->find($event->serialId);
        $session = SessionInstance::query()->with(['doctor', 'branch'])->find($event->sessionInstanceId);

        if ($serial === null || $session === null) {
            return;
        }

        $this->broadcaster->serialCalled($session, $serial, $event->previousNowServingSerialId);
    }
}
