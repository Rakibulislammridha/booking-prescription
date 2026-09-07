<?php

declare(strict_types=1);

namespace App\Domain\Serials\Events;

use App\Models\Tenant\Serial;
use App\Models\Tenant\SessionInstance;

/** Consumers: Queue (InvalidateQueueState), Billing (invoice line), Notifications (booking confirmed). */
final class SerialAllocated extends SerialEvent
{
    public function __construct(Serial $serial, ?SessionInstance $session = null, public readonly ?int $appointmentId = null)
    {
        parent::__construct($serial, $session);
    }
}
