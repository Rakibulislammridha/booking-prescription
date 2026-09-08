<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** A platform invoice was settled — reactivation, receipts and the ledger hang off this. */
final class SubscriptionPaymentReceived implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $tenantId,
        public readonly int $invoiceId,
        public readonly int $paymentId,
        public readonly int $amountPaisa,
        public readonly string $method,
    ) {}
}
