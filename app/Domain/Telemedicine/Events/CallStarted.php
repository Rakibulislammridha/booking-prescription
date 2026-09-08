<?php

declare(strict_types=1);

namespace App\Domain\Telemedicine\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** The doctor opened the room and the consultation clock is running. */
final class CallStarted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $tenantId,
        public readonly int $roomId,
        public readonly int $sessionId,
        public readonly ?int $visitId,
        public readonly ?int $serialId,
    ) {}
}
