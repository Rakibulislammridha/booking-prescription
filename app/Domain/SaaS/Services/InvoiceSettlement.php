<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Services;

use App\Domain\SaaS\Enums\SubscriptionInvoiceStatus;
use App\Domain\SaaS\Enums\SubscriptionPaymentStatus;
use App\Models\Central\SubscriptionInvoice;
use App\Models\Central\SubscriptionPayment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Recompute an invoice from its SUCCEEDED payments, under a row lock.
 *
 * `paid_paisa` is derived, never incremented: two settlements landing at the same moment (a webhook and the
 * browser return of the same transaction, say) would otherwise both read the same "before" total and each write
 * their own amount, losing one of them. `SELECT … FOR UPDATE` + `SUM` makes the answer a function of the rows
 * that exist, so it does not matter how many writers raced to create them or in what order they commit.
 *
 * `paid_paisa <= total_paisa` is a table CHECK, so an over-payment is clamped here rather than raising a
 * constraint violation at the end of a payment the customer has already made.
 */
final class InvoiceSettlement
{
    /** @return bool true when this call is the one that settled the invoice */
    public function apply(SubscriptionInvoice $invoice): bool
    {
        return (bool) DB::connection('pgsql')->transaction(function () use ($invoice): bool {
            /** @var SubscriptionInvoice $locked */
            $locked = SubscriptionInvoice::query()->lockForUpdate()->findOrFail($invoice->id);
            $wasPaid = $locked->status === SubscriptionInvoiceStatus::Paid;

            $paid = (int) SubscriptionPayment::query()
                ->where('subscription_invoice_id', $locked->id)
                ->where('status', SubscriptionPaymentStatus::Succeeded->value)
                ->sum('amount_paisa');

            $settled = $paid >= $locked->total_paisa && $locked->total_paisa > 0;

            $locked->forceFill([
                'paid_paisa' => min($paid, $locked->total_paisa),
                'status' => $settled ? SubscriptionInvoiceStatus::Paid : $locked->status,
                'paid_at' => $settled ? ($locked->getAttribute('paid_at') ?? CarbonImmutable::now()) : $locked->getAttribute('paid_at'),
            ])->save();

            $invoice->setRawAttributes($locked->getAttributes(), true);

            return $settled && ! $wasPaid;
        });
    }
}
