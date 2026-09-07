<?php

declare(strict_types=1);

namespace App\Domain\Billing\Events;

use App\Models\Tenant\Invoice;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** The bill became real: totals and the doctor's commission are frozen. Consumers: Notifications, Reports. */
final class InvoiceIssued implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly Invoice $invoice) {}
}
