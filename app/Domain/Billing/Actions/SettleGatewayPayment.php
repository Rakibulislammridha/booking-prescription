<?php

declare(strict_types=1);

namespace App\Domain\Billing\Actions;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Billing\Contracts\PaymentGatewayDriver;
use App\Domain\Billing\Data\GatewayCallback;
use App\Domain\Billing\Data\GatewayVerification;
use App\Domain\Billing\Data\PaymentResult;
use App\Domain\Billing\Enums\PaymentMethod;
use App\Domain\Billing\Enums\PaymentTxnStatus;
use App\Domain\Billing\Enums\RefundReason;
use App\Domain\Billing\Enums\RefundStatus;
use App\Domain\Billing\Events\PaymentRecorded;
use App\Domain\Billing\Exceptions\GatewayAmountMismatch;
use App\Domain\Billing\Exceptions\GatewaySignatureInvalid;
use App\Domain\Billing\Services\CurrentShift;
use App\Domain\Billing\Services\InvoiceLedger;
use App\Domain\Billing\Services\Paisa;
use App\Models\Tenant\Invoice;
use App\Models\Tenant\Payment;
use App\Models\Tenant\Refund;
use Illuminate\Support\Facades\DB;

/**
 * Turn a verified callback into money on the books. Replay-safe by construction:
 *
 *  1. the callback is matched to the pending payment WE created (`gateway_payment_ref`), inside a row lock;
 *  2. a payment that is already terminal returns its existing result — a re-delivered webhook is a 200 no-op;
 *  3. the amount is read from the gateway server-to-server and must equal the amount frozen at checkout, else
 *     nothing is credited and the payment is marked failed (`GatewayAmountMismatch`);
 *  4. `gateway_txn_id` is written under `payments_gateway_gateway_txn_id_uniq_p`, so the same transaction id
 *     can never be claimed by a second payment row;
 *  5. the invoice balance is RECOMPUTED from rows, never incremented.
 */
final class SettleGatewayPayment
{
    public function __construct(
        private readonly InvoiceLedger $ledger,
        private readonly CurrentShift $shifts,
        private readonly AuditRecorder $audit,
    ) {}

    public function handle(PaymentGatewayDriver $driver, GatewayCallback $callback): PaymentResult
    {
        $payment = $this->locate($callback);

        if ($payment === null) {
            throw new GatewaySignatureInvalid(['gateway' => $callback->gateway->value]);
        }

        if ($payment->status->isTerminal()) {
            return new PaymentResult($payment, $payment->invoice()->firstOrFail(), duplicate: true);
        }

        // Server-to-server truth, outside the transaction: never hold a row lock across a network call.
        $verification = $driver->verifyTransaction($callback, $payment);

        // The tamper case is settled BEFORE the crediting transaction, in its own committed write, so that the
        // exception cannot roll back the very record that says "this payment must never be credited".
        if ($verification->succeeded && $verification->amountPaisa !== $payment->amount_paisa) {
            $this->rejectMismatch($payment, $verification);
        }

        return DB::transaction(function () use ($payment, $callback, $verification): PaymentResult {
            /** @var Payment $locked */
            $locked = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            /** @var Invoice $invoice */
            $invoice = Invoice::query()->whereKey($locked->invoice_id)->lockForUpdate()->firstOrFail();

            if ($locked->status->isTerminal()) {
                return new PaymentResult($locked, $invoice, duplicate: true);
            }

            if (! $verification->succeeded) {
                $locked->forceFill([
                    'status' => $callback->isCancelled() ? PaymentTxnStatus::Cancelled : PaymentTxnStatus::Failed,
                    'failed_reason' => mb_substr((string) ($verification->failureReason ?? $callback->rawStatus), 0, 255),
                    'gateway_payload' => $verification->payload,
                ])->save();

                $this->auditRow($locked, 'gateway_failed', ['reason' => $locked->failed_reason]);

                return new PaymentResult($locked, $invoice);
            }

            $locked->forceFill([
                'status' => PaymentTxnStatus::Succeeded,
                'gateway_txn_id' => $verification->gatewayTxnId,
                'gateway_payload' => $verification->payload,
                'receipt_number' => $locked->receipt_number ?? Payment::nextReceiptNumber(),
                'paid_at' => now(),
            ])->save();

            // The bill shrank while the patient was at the gateway (a counter payment or a waiver landed).
            // The money is real: record it, and put the excess on the books as a refund the desk must hand back.
            $this->offsetOverpayment($locked, $invoice);

            $synced = $this->ledger->sync($invoice->refresh());
            $this->auditRow($locked, 'gateway_settled', ['amount_paisa' => $locked->amount_paisa, 'txn_id' => $locked->gateway_txn_id]);

            DB::afterCommit(fn () => PaymentRecorded::dispatch($locked, $synced));

            return new PaymentResult($locked, $synced);
        });
    }

    /**
     * The gateway reported an amount other than the one we froze at checkout — the classic tampering signal.
     * The payment is marked failed and audited in its own committed transaction, and nothing is credited.
     *
     * @throws GatewayAmountMismatch
     */
    private function rejectMismatch(Payment $payment, GatewayVerification $verification): never
    {
        DB::transaction(function () use ($payment, $verification): void {
            /** @var Payment $locked */
            $locked = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();

            if (! $locked->status->isTerminal()) {
                $locked->forceFill([
                    'status' => PaymentTxnStatus::Failed,
                    'failed_reason' => mb_substr(__('billing.errors.gateway_amount_mismatch', [
                        'expected' => Paisa::toDecimal($locked->amount_paisa),
                        'actual' => Paisa::toDecimal($verification->amountPaisa),
                    ]), 0, 255),
                    'gateway_payload' => $verification->payload,
                ])->save();

                $this->auditRow($locked, 'gateway_amount_mismatch', [
                    'expected_paisa' => $locked->amount_paisa,
                    'reported_paisa' => $verification->amountPaisa,
                ]);
            }
        });

        throw new GatewayAmountMismatch([
            'expected' => Paisa::toDecimal($payment->amount_paisa),
            'actual' => Paisa::toDecimal($verification->amountPaisa),
        ]);
    }

    /** The pending row this callback belongs to — matched on OUR reference, scoped to the gateway. */
    private function locate(GatewayCallback $callback): ?Payment
    {
        $query = Payment::query()->where('gateway', $callback->gateway->value);

        if ($callback->merchantRef !== null && $callback->merchantRef !== '') {
            $found = (clone $query)->where('gateway_payment_ref', $callback->merchantRef)->first();

            if ($found !== null) {
                return $found;
            }
        }

        if ($callback->gatewayTxnId !== null && $callback->gatewayTxnId !== '') {
            return (clone $query)->where('gateway_txn_id', $callback->gatewayTxnId)->first();
        }

        return null;
    }

    private function offsetOverpayment(Payment $payment, Invoice $invoice): void
    {
        $settled = (int) $invoice->payments()->settled()->sum('amount_paisa');
        $refunded = (int) $invoice->refunds()->where('status', RefundStatus::Processed->value)->sum('amount_paisa');
        $excess = $settled - $refunded - $invoice->total_paisa;

        if ($excess <= 0) {
            return;
        }

        $refund = new Refund;
        $refund->forceFill([
            'payment_id' => $payment->id,
            'invoice_id' => $invoice->id,
            'amount_paisa' => min($excess, $payment->amount_paisa),
            'method' => PaymentMethod::Cash,
            'status' => RefundStatus::Processed,
            'reason_code' => RefundReason::Duplicate,
            'reason_note' => mb_substr(__('billing.refund.note.overpayment'), 0, 255),
            'cash_shift_id' => $this->shifts->idForUser($invoice->created_by_user_id),
            'processed_at' => now(),
        ])->save();

        $payment->forceFill([
            'refunded_paisa' => min($refund->amount_paisa, $payment->amount_paisa),
            'status' => $refund->amount_paisa >= $payment->amount_paisa ? PaymentTxnStatus::Refunded : PaymentTxnStatus::PartiallyRefunded,
        ])->save();
    }

    /** @param array<string, mixed> $after */
    private function auditRow(Payment $payment, string $event, array $after): void
    {
        $this->audit->record(AuditAction::Update, $payment, null, $after + ['status' => $payment->status->value], ['event' => $event, 'gateway' => $payment->gateway?->value, 'actor_source' => 'api']);
    }
}
