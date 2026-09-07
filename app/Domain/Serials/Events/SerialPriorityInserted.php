<?php

declare(strict_types=1);

namespace App\Domain\Serials\Events;

use App\Models\Tenant\Serial;
use App\Models\Tenant\SessionInstance;

/** Consumers: Queue. */
final class SerialPriorityInserted extends SerialEvent
{
    public function __construct(Serial $serial, ?SessionInstance $session = null, public readonly int $fromPosition = 0, public readonly int $toPosition = 0, public readonly string $priority = 'normal')
    {
        parent::__construct($serial, $session);
    }
}
