<?php

declare(strict_types=1);

namespace App\Domain\Queue\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * "N patients ahead of you, please reach the chamber" (REALTIME.md §7). Raised by the Queue module's
 * `NotifyApproachingSerials` listener once per serial — the dedupe IS the `t3_notified_at IS NULL` predicate of the
 * single `UPDATE … RETURNING`. Not a broadcast: the Notifications module consumes it (ARCHITECTURE §5.4).
 */
final class SerialApproaching
{
    use Dispatchable;

    public function __construct(
        public readonly int $serialId,
        public readonly string $serialPublicId,
        public readonly int $sessionInstanceId,
        public readonly string $sessionPublicId,
        public readonly string $displayCode,
        public readonly int $ahead,
        public readonly ?int $patientId = null,
    ) {}
}
