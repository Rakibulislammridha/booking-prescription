<?php

declare(strict_types=1);

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\Data\PaymentRequest;
use App\Domain\Billing\Data\PaymentResult;
use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Enums\PaymentTxnStatus;
use App\Domain\Billing\Events\PaymentRecorded;
use App\Domain\Billing\Exceptions\InvoiceNotPayable;
use App\Domain\Billing\Exceptions\PaymentExceedsDue;
use App\Domain\Billing\Services\InvoiceLedger;
use App\Domain\Billing\Services\Paisa;
use App\Domain\Shared\Actor;
use App\Models\Tenant\Invoice;
use App\Models\Tenant\Payment;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * The single door money comes in through — counter cash, counter card and every gateway settlement. Everything
 * about it is built so that a retry is a no-op rather than a second charge:
 *
 *  1. the request is looked up by `idempotency_key` BEFORE anything is written, and again inside the row lock;
 *  2. `payments_idempotency_key_uniq`, `payments_receipt_number_uniq_p` and
 *     `payments_gateway_gateway_txn_id_uniq_p` make the database the final arbiter — a unique violation is
 *     caught and resolved into the existing row, never surfaced as an error the caller might retry;
 *  3. the invoice's `paid_paisa` is RECOMPUTED from the payment and refund rows (InvoiceLedger), never
 *     incremented, so even a hypothetical duplicate row could not inflate the balance twice;
 *  4. the amount may never exceed the outstanding `due_paisa` (`paid_paisa <= total_paisa` is also a CHECK).
 *
 * A payment row, once written, is never re-amounted or deleted: a reversal is a `refunds` row.
 */
final class RecordPayment
{
    public function __construct(private readonly InvoiceLedger $ledger) {}

    public function handle(Invoice $invoice, PaymentRequest $request, Actor $actor): PaymentResult
    {
        $existing = $this->findExisting($request);

        if ($existing !== null) {
            return new PaymentResult($existing, $existing->invoice()->firstOrFail(), duplicate: true);
        }

        try {
            return DB::transaction(fn (): PaymentResult => $this->write($invoice, $request, $actor));
        } catch (QueryException $e) {
            $duplicate = $this->findExisting($request);

            if ($duplicate === null) {
                throw $e;
            }

            return new PaymentResult($duplicate, $duplicate->invoice()->firstOrFail(), duplicate: true);
        }
    }

    private function write(Invoice $invoice, PaymentRequest $request, Actor $actor): PaymentResult
    {
        /** @var Invoice $locked */
        $locked = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

        $duplicate = $this->findExisting($request);

        if ($duplicate !== null) {
            return new PaymentResult($duplicate, $locked, duplicate: true);
        }

        if ($request->status->isSettled()) {
            $this->assertPayable($locked, $request->amountPaisa);
        }

        $payment = new Payment;
        $payment->forceFill([
            'invoice_id' => $locked->id,
            'patient_id' => $locked->patient_id,
            'receipt_number' => $this->receiptNumber($request),
            'method' => $request->method,
            'status' => $request->status,
            'amount_paisa' => $request->amountPaisa,
            'refunded_paisa' => 0,
            'gateway' => $request->gateway ?? $request->method->gateway(),
            'gateway_txn_id' => $request->gatewayTxnId,
            'gateway_payment_ref' => $request->gatewayPaymentRef,
            'gateway_payload' => $request->gatewayPayload,
            'idempotency_key' => $request->idempotencyKey,
            'client_event_id' => $request->clientEventId,
            'received_by_user_id' => $actor->userId,
            'reception_device_id' => $request->receptionDeviceId ?? $actor->deviceId,
            'cash_shift_id' => $request->cashShiftId,
            'paid_at' => $request->status->isSettled() ? ($request->paidAt ?? now()) : null,
            'failed_reason' => $request->failedReason,
        ])->save();

        $synced = $this->ledger->sync($locked->refresh());

        DB::afterCommit(fn () => PaymentRecorded::dispatch($payment, $synced));

        return new PaymentResult($payment, $synced);
    }

    /** @throws InvoiceNotPayable|PaymentExceedsDue */
    private function assertPayable(Invoice $invoice, int $amountPaisa): void
    {
        if (! $invoice->status->isPayable()) {
            throw new InvoiceNotPayable(['status' => $invoice->status->value]);
        }

        if ($amountPaisa <= 0 || $amountPaisa > $invoice->due_paisa) {
            throw new PaymentExceedsDue([
                'amount' => Paisa::toDecimal($amountPaisa),
                'due' => Paisa::toDecimal($invoice->due_paisa),
            ]);
        }
    }

    /**
     * A succeeded payment always carries a receipt. The desk's own number (online `C-…`, offline `D2-000123`)
     * wins when supplied — the patient already holds that slip — and only otherwise is `receipt_number_seq`
     * consumed, so a replay never burns a sequence value.
     */
    private function receiptNumber(PaymentRequest $request): ?string
    {
        if ($request->receiptNumber !== null && $request->receiptNumber !== '') {
            return $request->receiptNumber;
        }

        return $request->status->isSettled() ? Payment::nextReceiptNumber() : null;
    }

    /** Every uniqueness guarantee the schema gives us, tried in order. */
    private function findExisting(PaymentRequest $request): ?Payment
    {
        $payment = Payment::query()->where('idempotency_key', $request->idempotencyKey)->first();

        if ($payment !== null) {
            return $payment;
        }

        if ($request->gateway !== null && $request->gatewayTxnId !== null) {
            $payment = Payment::query()->where('gateway', $request->gateway->value)->where('gateway_txn_id', $request->gatewayTxnId)->first();

            if ($payment !== null) {
                return $payment;
            }
        }

        if ($request->receiptNumber !== null && $request->receiptNumber !== '') {
            $payment = Payment::query()->where('receipt_number', $request->receiptNumber)->first();

            if ($payment !== null) {
                return $payment;
            }
        }

        if ($request->receptionDeviceId !== null && $request->clientEventId !== null) {
            return Payment::query()->where('reception_device_id', $request->receptionDeviceId)->where('client_event_id', $request->clientEventId)->first();
        }

        return null;
    }

    /** A draft invoice cannot take money; the caller issues it first. */
    public static function requiresIssuedInvoice(Invoice $invoice): bool
    {
        return $invoice->status === InvoiceStatus::Draft;
    }

    /** Mark a pending gateway payment as failed/cancelled without ever touching the invoice balance. */
    public function fail(Payment $payment, string $reason, PaymentTxnStatus $status = PaymentTxnStatus::Failed): Payment
    {
        if ($payment->status->isTerminal()) {
            return $payment;
        }

        $payment->forceFill(['status' => $status, 'failed_reason' => mb_substr($reason, 0, 255)])->save();

        return $payment;
    }
}
