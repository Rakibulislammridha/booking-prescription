<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** A notification reached its terminal `failed` state — the dead-letter row the panel surfaces for a human. */
final class NotificationDeadLettered
{
    use Dispatchable;

    public function __construct(
        public readonly int $notificationId,
        public readonly string $errorCode,
        public readonly bool $permanent,
    ) {}
}
