<?php

declare(strict_types=1);

namespace App\Domain\Billing\Events;

use App\Models\Tenant\Refund;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** Money out (or a refund request raised). Consumers: Notifications, Reports. */
final class RefundIssued implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly Refund $refund) {}
}
