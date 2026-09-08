<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Actions\Billing;

use App\Domain\SaaS\Data\RecordPaymentData;
use App\Domain\SaaS\Enums\SubscriptionInvoiceStatus;
use App\Domain\SaaS\Enums\SubscriptionPaymentStatus;
use App\Domain\SaaS\Events\SubscriptionPaymentReceived;
use App\Domain\SaaS\Exceptions\InvoiceNotPayable;
use App\Domain\SaaS\Services\InvoiceSettlement;
use App\Models\Central\SubscriptionInvoice;
use App\Models\Central\SubscriptionPayment;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;

/**
 * Money lands against a platform invoice — a bank transfer or bKash payment a super admin records, a cash
 * settlement, or a correction. Billing's rules, applied to the control plane:
 *
 *  · integer paisa, no floats, no rounding anywhere;
 *  · REPLAY SAFE — `idempotency_key` and `(method, gateway_txn_id)` both carry partial unique indexes, so the
 *    same instruction applied twice settles the invoice once. The 23505 is caught and the FIRST row is returned,
 *    because a caller replaying a request wants the same answer, not an error;
 *  · a correction is a NEW row, never an edit of an existing payment;
 *  · the invoice total is recomputed from the succeeded rows under a lock (`InvoiceSettlement`), not incremented.
 *
 * Reactivation is not done here. `ReactivateOnPayment` listens for the event, so a clinic comes back the same way
 * whether the money arrived from a gateway, a super admin or a console command.
 */
final class RecordSubscriptionPayment
{
    public function __construct(private readonly InvoiceSettlement $settlement) {}

    public function handle(SubscriptionInvoice $invoice, RecordPaymentData $data): SubscriptionPayment
    {
        $existing = $this->existing($data);

        if ($existing !== null) {
            return $existing;
        }

        if (! in_array($invoice->status, [SubscriptionInvoiceStatus::Issued, SubscriptionInvoiceStatus::Overdue], true)) {
            throw new InvoiceNotPayable($invoice->number, $invoice->status->value);
        }

        if ($data->amountPaisa <= 0 || $data->amountPaisa > $invoice->total_paisa - (int) $invoice->getAttribute('paid_paisa')) {
            throw new InvoiceNotPayable($invoice->number, 'amount');
        }

        try {
            $payment = SubscriptionPayment::query()->create([
                'tenant_id' => $invoice->tenant_id,
                'subscription_invoice_id' => $invoice->id,
                'method' => $data->method,
                'status' => SubscriptionPaymentStatus::Succeeded,
                'amount_paisa' => $data->amountPaisa,
                'gateway_txn_id' => $data->gatewayTxnId,
                'gateway_payload' => $data->gatewayPayload,
                'idempotency_key' => $data->idempotencyKey,
                'paid_at' => CarbonImmutable::now(),
                'recorded_by_super_admin_id' => $data->recordedBySuperAdminId,
            ]);
        } catch (QueryException $e) {
            if (($e->errorInfo[0] ?? null) !== '23505') {
                throw $e;
            }

            // A concurrent delivery of the same instruction won the unique index; its row is the answer.
            return $this->existing($data) ?? throw $e;
        }

        if ($this->settlement->apply($invoice)) {
            SubscriptionPaymentReceived::dispatch($invoice->tenant_id, $invoice->id, $payment->id, $payment->amount_paisa, $payment->method->value);
        }

        return $payment;
    }

    private function existing(RecordPaymentData $data): ?SubscriptionPayment
    {
        if ($data->idempotencyKey !== null) {
            return SubscriptionPayment::query()->where('idempotency_key', $data->idempotencyKey)->first();
        }

        if ($data->gatewayTxnId !== null) {
            return SubscriptionPayment::query()->where('method', $data->method->value)->where('gateway_txn_id', $data->gatewayTxnId)->first();
        }

        return null;
    }
}
