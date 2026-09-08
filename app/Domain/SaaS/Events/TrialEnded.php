<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** A trial reached its end: either it converted to a paid period or it produced the first invoice. */
final class TrialEnded implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $tenantId,
        public readonly int $subscriptionId,
        public readonly bool $converted,
    ) {}
}
