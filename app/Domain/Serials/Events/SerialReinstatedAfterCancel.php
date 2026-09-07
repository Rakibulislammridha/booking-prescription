<?php

declare(strict_types=1);

namespace App\Domain\Serials\Events;

use App\Models\Tenant\Serial;
use App\Models\Tenant\SessionInstance;

/** cancelled → checked_in, only from the OFFLINE.md §8.4 resolution. Consumers: Billing (void pending refund), Queue. */
final class SerialReinstatedAfterCancel extends SerialEvent
{
    public function __construct(Serial $serial, ?SessionInstance $session = null, public readonly ?string $priorRefundStatus = null)
    {
        parent::__construct($serial, $session);
    }
}
