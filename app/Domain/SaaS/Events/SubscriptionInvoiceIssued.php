<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** A platform invoice left draft: the tenant now owes money and the dunning clock starts at its due date. */
final class SubscriptionInvoiceIssued implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $tenantId,
        public readonly int $invoiceId,
        public readonly string $number,
        public readonly int $totalPaisa,
    ) {}
}
