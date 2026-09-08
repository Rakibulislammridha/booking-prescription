<?php

declare(strict_types=1);

namespace App\Domain\Telemedicine\Events;

use App\Domain\Telemedicine\Enums\SessionEndReason;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Consumers: SaaS usage (`telemedicine_minutes`), Reports. */
final class CallEnded
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly int $tenantId,
        public readonly int $roomId,
        public readonly int $sessionId,
        public readonly ?int $visitId,
        public readonly int $durationSeconds,
        public readonly SessionEndReason $reason,
    ) {}
}
