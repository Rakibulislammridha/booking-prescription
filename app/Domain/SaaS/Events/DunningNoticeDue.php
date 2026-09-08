<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** Step N of the ladder came due for an unpaid invoice (App\\Domain\\SaaS\\Support\\DunningSchedule). */
final class DunningNoticeDue implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $tenantId,
        public readonly int $invoiceId,
        public readonly int $step,
        public readonly bool $isFinalNotice,
        public readonly string $suspendsOn,
    ) {}
}
