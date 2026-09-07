<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * ARCHITECTURE §5.4 — consumed by SaaS (`IncrementSmsCredits`, `usage_counters.sms_credits`). Raised on gateway
 * SUCCESS only: a clinic is billed for segments that left the gateway, never for attempts that were refused.
 */
final class SmsSent implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $tenantId,
        public readonly int $notificationId,
        public readonly int $segments,
    ) {}
}
