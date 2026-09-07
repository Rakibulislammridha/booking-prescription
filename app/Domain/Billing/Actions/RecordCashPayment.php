<?php

declare(strict_types=1);

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\Data\PaymentRequest;
use App\Domain\Billing\Data\PaymentResult;
use App\Domain\Billing\Enums\PaymentMethod;
use App\Domain\Billing\Enums\PaymentTxnStatus;
use App\Domain\Billing\Services\CurrentShift;
use App\Domain\Shared\Actor;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Invoice;
use App\Models\Tenant\Payment;
use Carbon\CarbonImmutable;

/**
 * Counter cash (and card) against an appointment — the action OFFLINE §7.2 names for the `collect_cash` replay
 * and the one the desk's "collect fee" button ends in.
 *
 * The receipt number IS the idempotency handle: the desk mints `C-{appointment}` online and the device mints
 * `D{n}-{seq}` offline, unique clinic-wide by construction (OFFLINE §6.1). It becomes `payments.idempotency_key`
 * (UNIQUE) and `payments.receipt_number` (UNIQUE), so replaying the same slip is a no-op with `duplicate = true`
 * and the patient is never charged twice.
 *
 * The invoice is created and issued on the way in when the booking has none yet, so a walk-in who pays at the
 * counter gets a real, printable bill without anyone opening a billing screen.
 */
final class RecordCashPayment
{
    public function __construct(
        private readonly CreateInvoiceForAppointment $invoices,
        private readonly IssueInvoice $issue,
        private readonly RecordPayment $payments,
        private readonly CurrentShift $shifts,
    ) {}

    public function handle(
        Appointment $appointment,
        int $amountPaisa,
        string $receiptNo,
        Actor $actor,
        PaymentMethod $method = PaymentMethod::Cash,
        ?CarbonImmutable $collectedAt = null,
        ?string $clientEventId = null,
        ?int $receptionDeviceId = null,
    ): PaymentResult {
        $invoice = $this->payableInvoice($appointment, $actor);

        // Replay idempotency is checked FIRST — before the "nothing left to pay" rule — so that the second
        // delivery of an offline event is reported as a duplicate of the payment it already created, instead of
        // looking like a fresh ৳0 collection against a bill its own first delivery settled.
        $existing = Payment::query()
            ->where('idempotency_key', self::idempotencyKey($receiptNo))
            ->orWhere('receipt_number', $receiptNo)
            ->first();

        if ($existing !== null) {
            return new PaymentResult($existing, $invoice, duplicate: true);
        }

        // A fully waived bill (free follow-up) has nothing to collect: report the settled invoice, take no money.
        if ($invoice->due_paisa <= 0) {
            return new PaymentResult($this->zeroPayment($invoice, $receiptNo, $method, $actor, $collectedAt)->payment, $invoice->refresh(), duplicate: false);
        }

        $request = new PaymentRequest(
            method: $method,
            amountPaisa: min(max(0, $amountPaisa), $invoice->due_paisa),
            idempotencyKey: self::idempotencyKey($receiptNo),
            status: PaymentTxnStatus::Succeeded,
            receiptNumber: $receiptNo,
            clientEventId: $clientEventId,
            receptionDeviceId: $receptionDeviceId ?? $actor->deviceId,
            cashShiftId: $this->shifts->idForUser($actor->userId),
            paidAt: $collectedAt,
        );

        return $this->payments->handle($invoice, $request, $actor);
    }

    /** The invoice money can actually land on: created from the fee snapshot and issued if it was a draft. */
    public function payableInvoice(Appointment $appointment, Actor $actor): Invoice
    {
        $invoice = $this->invoices->handle($appointment, $actor, $this->shifts->idForUser($actor->userId));

        return $invoice->isDraft() ? $this->issue->handle($invoice, $actor) : $invoice;
    }

    /** Was this receipt already recorded? (the replay check the CashCollector contract asks for) */
    public function wasRecorded(string $receiptNo): bool
    {
        return Payment::query()
            ->where('idempotency_key', self::idempotencyKey($receiptNo))
            ->orWhere('receipt_number', $receiptNo)
            ->exists();
    }

    /** Stable, 64 chars max, and namespaced so a receipt number can never collide with a gateway reference. */
    public static function idempotencyKey(string $receiptNo): string
    {
        return mb_substr('rcpt:'.$receiptNo, 0, 64);
    }

    /**
     * Nothing due: no `payments` row is written (amount_paisa > 0 is a CHECK, and a zero charge is not money).
     * The caller still gets a coherent result carrying the settled invoice.
     */
    private function zeroPayment(Invoice $invoice, string $receiptNo, PaymentMethod $method, Actor $actor, ?CarbonImmutable $collectedAt): PaymentResult
    {
        $payment = new Payment;
        $payment->forceFill([
            'invoice_id' => $invoice->id,
            'patient_id' => $invoice->patient_id,
            'receipt_number' => $receiptNo,
            'method' => $method,
            'status' => PaymentTxnStatus::Succeeded,
            'amount_paisa' => 0,
            'idempotency_key' => self::idempotencyKey($receiptNo),
            'paid_at' => $collectedAt ?? now(),
        ]);

        return new PaymentResult($payment, $invoice);
    }
}
