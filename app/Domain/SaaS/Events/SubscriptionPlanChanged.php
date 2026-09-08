<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** The tenant moved between plans; entitlements change immediately, the price at the next renewal. */
final class SubscriptionPlanChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $tenantId,
        public readonly int $subscriptionId,
        public readonly string $fromPlanCode,
        public readonly string $toPlanCode,
    ) {}
}
