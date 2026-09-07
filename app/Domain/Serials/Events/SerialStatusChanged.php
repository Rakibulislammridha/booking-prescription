<?php

declare(strict_types=1);

namespace App\Domain\Serials\Events;

use App\Domain\Shared\Actor;
use App\Models\Tenant\Serial;
use App\Models\Tenant\SessionInstance;

/** Every status transition (SerialTransition::apply). Consumers: Queue, Audit. */
final class SerialStatusChanged extends SerialEvent
{
    public function __construct(Serial $serial, ?SessionInstance $session = null, public readonly string $from = '', public readonly string $to = '', public readonly ?Actor $actor = null)
    {
        parent::__construct($serial, $session);
    }
}
