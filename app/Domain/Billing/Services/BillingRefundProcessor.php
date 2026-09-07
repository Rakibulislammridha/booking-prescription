<?php

declare(strict_types=1);

namespace App\Domain\Billing\Services;

use App\Domain\Billing\Actions\IssueRefund;
use App\Domain\Billing\Actions\RecordCashPayment;
use App\Domain\Billing\Data\RefundRequest;
use App\Domain\Billing\Enums\PaymentMethod;
use App\Domain\Billing\Enums\PaymentTxnStatus;
use App\Domain\Billing\Enums\RefundReason;
use App\Domain\Billing\Enums\RefundStatus;
use App\Domain\Booking\Contracts\RefundProcessor;
use App\Domain\Shared\Actor;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Invoice;
use App\Models\Tenant\Payment;
use App\Models\Tenant\Refund;
use Illuminate\Support\Facades\DB;

/**
 * The real implementation of the Booking seam (`App\Domain\Booking\Contracts\RefundProcessor`), replacing
 * `NullRefundProcessor`. Cancellation, the offline `refund_cash` resolution and the "keep it as credit"
 * resolution all end in real `refunds` rows against the payments that actually took the money.
 *
 * A cancellation raises the refund as `pending` rather than paying it out: money leaves the drawer when a human
 * with `billing.refunds.issue` says so, and the pending row is what the desk's refund queue shows.
 */
final class BillingRefundProcessor implements RefundProcessor
{
    public function __construct(
        private readonly IssueRefund $refunds,
        private readonly RefundEligibility $eligibility,
        private readonly InvoiceLedger $ledger,
        private readonly CurrentShift $shifts,
    ) {}

    /** @return array<string, mixed> */
    public function onCancellation(Appointment $appointment, bool $refundEligible, Actor $actor): array
    {
        $invoice = $this->invoiceFor($appointment);

        if ($invoice === null || $invoice->paid_paisa <= 0) {
            return ['status' => 'none', 'amount_paisa' => 0, 'eligible' => $refundEligible];
        }

        if (! $refundEligible) {
            return [
                'status' => 'not_eligible',
                'amount_paisa' => 0,
                'eligible' => false,
                'paid_paisa' => $invoice->paid_paisa,
                'invoice' => $invoice->public_id,
            ];
        }

        $reason = $appointment->cancel_reason_code === null
            ? RefundReason::PatientCancelled
            : RefundReason::fromCancelReason($appointment->cancel_reason_code);

        $created = [];

        foreach ($this->refundablePayments($invoice) as $payment) {
            $created[] = $this->refunds->handle($payment, new RefundRequest(
                reasonCode: $reason,
                amountPaisa: null,
                note: __('billing.refund.note.cancellation'),
                autoProcess: false,
            ), $actor);
        }

        return [
            'status' => $created === [] ? 'none' : 'pending',
            'amount_paisa' => array_sum(array_map(fn (Refund $r) => $r->amount_paisa, $created)),
            'eligible' => true,
            'invoice' => $invoice->public_id,
            'refunds' => array_map(fn (Refund $r) => ['id' => $r->id, 'amount_paisa' => $r->amount_paisa, 'status' => $r->status->value], $created),
        ];
    }

    /** OFFLINE §8.5 `refund_cash`: cash taken during the outage is handed straight back at the counter. */
    public function refundCash(Appointment $appointment, int $amountPaisa, string $receiptNo, Actor $actor): array
    {
        $invoice = $this->invoiceFor($appointment);
        $payment = $invoice === null ? null : Payment::query()
            ->where('invoice_id', $invoice->id)
            ->where(fn ($q) => $q->where('receipt_number', $receiptNo)->orWhere('idempotency_key', RecordCashPayment::idempotencyKey($receiptNo)))
            ->first();

        if ($payment === null) {
            // The cash was taken on a device but never posted (the replay conflicted on `already_paid`), and it
            // is being handed back now. Both facts are recorded — an unmatched payment and its reversal, netting
            // to zero — because "cash existed and was returned" is not the same as "no cash ever existed".
            return $this->recordAndReverseUnpostedCash($appointment, $invoice, $amountPaisa, $receiptNo, $actor);
        }

        $refund = $this->refunds->handle($payment, new RefundRequest(
            reasonCode: RefundReason::Duplicate,
            amountPaisa: min(max(0, $amountPaisa), $payment->amount_paisa),
            note: __('billing.refund.note.offline_duplicate'),
            method: PaymentMethod::Cash,
            autoProcess: true,
        ), $actor);

        // 'refunded' is the word the Reception conflict card and the offline sync contract expect.
        return ['status' => 'refunded', 'amount_paisa' => $refund->amount_paisa, 'receipt_no' => $receiptNo, 'refund_id' => $refund->id];
    }

    /**
     * OFFLINE §8.5 `credit`: the clinic keeps the cash. There is no patient-credit ledger in SCHEMA, so the
     * honest record is that the money stays on this invoice — no refund row, nothing to hand back — and the
     * decision is audited by the caller. Reported as `credited` so the desk card can say so.
     */
    public function credit(Appointment $appointment, int $amountPaisa, string $receiptNo, Actor $actor): array
    {
        $invoice = $this->invoiceFor($appointment);

        return [
            'status' => 'credited',
            'amount_paisa' => $amountPaisa,
            'receipt_no' => $receiptNo,
            'invoice' => $invoice?->public_id,
            'note' => __('billing.refund.note.kept_as_credit'),
        ];
    }

    /** Would a refund be due if this booking were cancelled now? (the desk's pre-flight question) */
    public function eligibility(): RefundEligibility
    {
        return $this->eligibility;
    }

    private function invoiceFor(Appointment $appointment): ?Invoice
    {
        return Invoice::query()->where('appointment_id', $appointment->id)->live()->first();
    }

    /** @return array<int, Payment> newest first, so the most recent money goes back first */
    private function refundablePayments(Invoice $invoice): array
    {
        return $invoice->payments()->settled()->orderByDesc('id')->get()
            ->filter(fn (Payment $p) => $p->netPaisa() > 0)
            ->values()
            ->all();
    }

    /**
     * OFFLINE §8.5 refund_cash for money that never reached the books: the payment row and an offsetting
     * `processed` refund are written together, inside one transaction, so `paid_paisa` is recomputed as
     * `in − out` and the invoice's `paid_paisa <= total_paisa` CHECK is never violated. Nothing is invented and
     * nothing disappears: the shift reconciliation sees the cash in and the cash out.
     *
     * @return array<string, mixed>
     */
    private function recordAndReverseUnpostedCash(Appointment $appointment, ?Invoice $invoice, int $amountPaisa, string $receiptNo, Actor $actor): array
    {
        $amount = max(0, $amountPaisa);

        if ($invoice === null || $amount === 0) {
            return ['status' => 'none', 'amount_paisa' => 0, 'receipt_no' => $receiptNo];
        }

        $existing = Payment::query()->where('idempotency_key', RecordCashPayment::idempotencyKey($receiptNo))->first();

        if ($existing !== null) {
            return ['status' => 'refunded', 'amount_paisa' => $existing->refunded_paisa, 'receipt_no' => $receiptNo, 'duplicate' => true];
        }

        $refund = DB::transaction(function () use ($invoice, $amount, $receiptNo, $actor): Refund {
            /** @var Invoice $locked */
            $locked = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $shiftId = $this->shifts->idForUser($actor->userId);

            $payment = new Payment;
            $payment->forceFill([
                'invoice_id' => $locked->id,
                'patient_id' => $locked->patient_id,
                'receipt_number' => $receiptNo,
                'method' => PaymentMethod::Cash,
                'status' => PaymentTxnStatus::Refunded,
                'amount_paisa' => $amount,
                'refunded_paisa' => $amount,
                'idempotency_key' => RecordCashPayment::idempotencyKey($receiptNo),
                'received_by_user_id' => $actor->userId,
                'reception_device_id' => $actor->deviceId,
                'cash_shift_id' => $shiftId,
                'paid_at' => now(),
            ])->save();

            $refund = new Refund;
            $refund->forceFill([
                'payment_id' => $payment->id,
                'invoice_id' => $locked->id,
                'amount_paisa' => $amount,
                'method' => PaymentMethod::Cash,
                'status' => RefundStatus::Processed,
                'reason_code' => RefundReason::Duplicate,
                'reason_note' => mb_substr(__('billing.refund.note.offline_duplicate'), 0, 255),
                'requested_by_user_id' => $actor->userId,
                'approved_by_user_id' => $actor->userId,
                'cash_shift_id' => $shiftId,
                'processed_at' => now(),
            ])->save();

            $this->ledger->sync($locked->refresh());

            return $refund;
        });

        return ['status' => 'refunded', 'amount_paisa' => $refund->amount_paisa, 'receipt_no' => $receiptNo, 'refund_id' => $refund->id];
    }
}
