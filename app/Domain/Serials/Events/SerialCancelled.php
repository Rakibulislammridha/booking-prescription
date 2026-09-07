<?php

declare(strict_types=1);

namespace App\Domain\Serials\Events;

use App\Models\Tenant\Serial;
use App\Models\Tenant\SessionInstance;

/** Billing decides refund/credit from refund_eligible; the engine never touches payments (SERIAL_ENGINE §15). */
final class SerialCancelled extends SerialEvent
{
    public function __construct(Serial $serial, ?SessionInstance $session = null, public readonly string $reasonCode = 'other', public readonly ?string $cancelledByRole = null, public readonly ?int $minutesBeforePlannedStart = null, public readonly bool $refundEligible = false)
    {
        parent::__construct($serial, $session);
    }
}
