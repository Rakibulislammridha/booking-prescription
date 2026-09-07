<?php

declare(strict_types=1);

namespace App\Domain\Billing\Services;

use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Enums\RefundStatus;
use App\Domain\Billing\Exceptions\DiscountBelowPaid;
use App\Domain\Booking\Enums\PaymentStatus;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Invoice;
use App\Models\Tenant\Payment;
use App\Models\Tenant\Refund;

/**
 * The ONLY writer of an invoice's derived money columns. Every payment, refund, discount and coupon ends here,
 * inside the caller's transaction with the invoice row locked, and the columns are recomputed from the rows —
 * never incremented. A lost or duplicated event therefore cannot drift the balance: re-running `sync()` on the
 * same rows produces the same numbers.
 *
 *   subtotal  Σ invoice_items.line_total_paisa            (recomputed while draft; FROZEN once issued)
 *   discount  Σ discounts.amount_paisa                    (capped at subtotal)
 *   coupon    coupon_redemptions.amount_paisa             (capped at what the discounts left)
 *   vat       base × billing.vat_percent                  (recomputed while draft; FROZEN once issued)
 *   total     subtotal − discount − coupon + vat          (SCHEMA §3.5's own definition)
 *   paid      Σ settled payments − Σ processed refunds    (clamped to [0, total])
 *   due       GENERATED total − paid                      (never written)
 *
 * Freezing subtotal and VAT at issue is deliberate: a tax already assessed on a printed bill is not silently
 * re-rated when the clinic's VAT setting changes months later. A post-issue waiver is a `discounts` row that
 * reduces the total by its face amount — the row is the credit note and the audit trail.
 */
final class InvoiceLedger
{
    public function __construct(
        private readonly InvoiceCalculator $calculator,
        private readonly VatRate $vat,
    ) {}

    /**
     * Recompute and persist. Call inside a transaction, on a row you hold `lockForUpdate()` on.
     *
     * @throws DiscountBelowPaid
     */
    public function sync(Invoice $invoice): Invoice
    {
        if ($invoice->status === InvoiceStatus::Void) {
            return $invoice;
        }

        $columns = $this->totals($invoice);
        $paid = $this->settledPaisa($invoice);

        if ($paid > $columns['total_paisa']) {
            throw new DiscountBelowPaid(['paid' => Paisa::toDecimal($paid), 'total' => Paisa::toDecimal($columns['total_paisa'])]);
        }

        $columns['paid_paisa'] = $paid;
        $columns['status'] = $this->status($invoice, $columns['total_paisa'], $paid);
        $columns['paid_at'] = $columns['status'] === InvoiceStatus::Paid ? ($invoice->paid_at ?? now()) : null;

        $invoice->forceFill($columns)->save();

        // `due_paisa` is GENERATED: only the database knows its new value, so the caller must get a fresh row.
        $invoice->refresh();

        $this->mirrorOntoAppointment($invoice);

        return $invoice;
    }

    /** What the invoice would total right now, without writing. Used by the desk before a discount is committed. */
    public function preview(Invoice $invoice): int
    {
        return $this->totals($invoice)['total_paisa'];
    }

    /** @return array<string, int> */
    private function totals(Invoice $invoice): array
    {
        $frozen = $invoice->status !== InvoiceStatus::Draft;
        $lines = $invoice->items()->pluck('line_total_paisa')->map(fn ($v) => (int) $v)->all();
        $subtotal = $frozen ? $invoice->subtotal_paisa : array_sum($lines);

        $discount = Paisa::clamp((int) $invoice->discounts()->sum('amount_paisa'), $subtotal);
        $coupon = Paisa::clamp((int) ($invoice->couponRedemption()->value('amount_paisa') ?? 0), $subtotal - $discount);
        $base = $subtotal - $discount - $coupon;
        $vat = $frozen ? $invoice->vat_paisa : Paisa::applyBasisPoints($base, $this->vat->basisPoints());

        return [
            'subtotal_paisa' => $subtotal,
            'discount_paisa' => $discount,
            'coupon_discount_paisa' => $coupon,
            'vat_paisa' => $vat,
            'total_paisa' => max(0, $base + $vat),
        ];
    }

    /** Settled money in, minus money that actually went back out. Never a running counter. */
    private function settledPaisa(Invoice $invoice): int
    {
        $in = (int) $invoice->payments()->settled()->sum('amount_paisa');
        $out = (int) $invoice->refunds()->where('status', RefundStatus::Processed->value)->sum('amount_paisa');

        return max(0, $in - $out);
    }

    private function status(Invoice $invoice, int $total, int $paid): InvoiceStatus
    {
        if ($invoice->status === InvoiceStatus::Draft) {
            return InvoiceStatus::Draft;
        }

        if ($total === 0) {
            // A fully waived bill (a free follow-up) is settled the moment it is issued.
            return InvoiceStatus::Paid;
        }

        if ($paid >= $total) {
            return InvoiceStatus::Paid;
        }

        if ($paid > 0) {
            return InvoiceStatus::PartiallyPaid;
        }

        $refunded = $invoice->refunds()->where('status', RefundStatus::Processed->value)->exists();

        return $refunded ? InvoiceStatus::Refunded : InvoiceStatus::Issued;
    }

    /**
     * `appointments.payment_status` is Booking's mirror of the same fact (the desk board reads it). Keeping it in
     * step here is the only place Billing writes another module's column, and it is written from the invoice —
     * never the other way round.
     */
    private function mirrorOntoAppointment(Invoice $invoice): void
    {
        if ($invoice->appointment_id === null) {
            return;
        }

        $appointment = Appointment::query()->find($invoice->appointment_id);

        if ($appointment === null) {
            return;
        }

        $status = match (true) {
            $invoice->status === InvoiceStatus::Refunded => PaymentStatus::Refunded,
            $invoice->status === InvoiceStatus::Paid => PaymentStatus::Paid,
            $invoice->paid_paisa > 0 => PaymentStatus::Partial,
            default => PaymentStatus::Unpaid,
        };

        if ($appointment->payment_status !== $status || $appointment->invoice_id !== $invoice->id) {
            $appointment->forceFill(['payment_status' => $status, 'invoice_id' => $invoice->id])->save();
        }
    }

    /** How much of one payment can still be refunded: its net, minus refunds already claiming it. */
    public function refundablePaisa(Payment $payment): int
    {
        $claimed = (int) Refund::query()
            ->where('payment_id', $payment->id)
            ->whereIn('status', [RefundStatus::Pending->value, RefundStatus::Approved->value, RefundStatus::Processed->value])
            ->sum('amount_paisa');

        return max(0, ($payment->status->isSettled() ? $payment->amount_paisa : 0) - $claimed);
    }

    public function calculator(): InvoiceCalculator
    {
        return $this->calculator;
    }
}
