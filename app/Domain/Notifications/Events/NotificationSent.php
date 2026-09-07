<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * A notification left the gateway successfully. The one fact other modules may treat as "delivery happened":
 * `RecordPrescriptionDelivery` uses it to narrow `prescriptions.delivered_channels` from "a send was requested"
 * to "a gateway accepted the message" (PRESCRIPTION.md §7.7's open question, closed here).
 */
final class NotificationSent
{
    use Dispatchable;

    public function __construct(
        public readonly int $tenantId,
        public readonly int $notificationId,
        public readonly string $eventKey,
        public readonly string $channel,
        public readonly ?string $notifiableType = null,
        public readonly ?int $notifiableId = null,
    ) {}
}
