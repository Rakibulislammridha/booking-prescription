<?php

declare(strict_types=1);

namespace App\Domain\Billing\Events;

use App\Models\Tenant\Invoice;
use App\Models\Tenant\Payment;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** Money in. Consumers: Notifications (receipt SMS), Reports, Queue (the desk board refresh). */
final class PaymentRecorded implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(public readonly Payment $payment, public readonly Invoice $invoice) {}
}
