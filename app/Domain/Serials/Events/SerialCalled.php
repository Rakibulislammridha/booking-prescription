<?php

declare(strict_types=1);

namespace App\Domain\Serials\Events;

use App\Models\Tenant\Serial;
use App\Models\Tenant\SessionInstance;

/** Consumers: Queue (broadcast now, 3-ahead notifications), auto no-show. */
final class SerialCalled extends SerialEvent
{
    public function __construct(Serial $serial, ?SessionInstance $session = null, public readonly ?int $previousNowServingSerialId = null)
    {
        parent::__construct($serial, $session);
    }
}
