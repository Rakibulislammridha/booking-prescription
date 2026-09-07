<?php

declare(strict_types=1);

namespace App\Domain\Serials\Events;

use App\Models\Tenant\Serial;
use App\Models\Tenant\SessionInstance;

/** reason auto|manual|session_closed. Consumers: Billing, Notifications, Queue. */
final class SerialNoShow extends SerialEvent
{
    public function __construct(Serial $serial, ?SessionInstance $session = null, public readonly string $reason = 'manual')
    {
        parent::__construct($serial, $session);
    }
}
