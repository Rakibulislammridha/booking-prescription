<?php

declare(strict_types=1);

namespace App\Domain\Serials\Events;

use App\Models\Tenant\Serial;
use App\Models\Tenant\SessionInstance;

/** no_show → checked_in|booked. Consumers: Queue. */
final class SerialReinstated extends SerialEvent
{
    public function __construct(Serial $serial, ?SessionInstance $session = null)
    {
        parent::__construct($serial, $session);
    }
}
