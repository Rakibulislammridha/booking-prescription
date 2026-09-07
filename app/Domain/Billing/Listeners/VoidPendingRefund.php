<?php

declare(strict_types=1);

namespace App\Domain\Billing\Listeners;

use App\Domain\Billing\Actions\IssueRefund;
use App\Domain\Billing\Enums\RefundStatus;
use App\Domain\Serials\Events\SerialReinstatedAfterCancel;
use App\Domain\Shared\Actor;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Invoice;
use App\Models\Tenant\Refund;

/**
 * ARCHITECTURE §5.4 — Billing's consumer of `SerialReinstatedAfterCancel`. The patient turned up after all, so a
 * refund that was raised but not yet paid out is rejected rather than handed over. A refund already `processed`
 * is history and is left alone: the money left the drawer, and the consultation is simply re-invoiced.
 */
final class VoidPendingRefund
{
    public function __construct(private readonly IssueRefund $refunds) {}

    public function handle(SerialReinstatedAfterCancel $event): void
    {
        $appointment = Appointment::query()->where('serial_id', $event->serialId)->first();

        if ($appointment === null) {
            return;
        }

        $invoice = Invoice::query()->where('appointment_id', $appointment->id)->live()->first();

        if ($invoice === null) {
            return;
        }

        $pending = Refund::query()
            ->where('invoice_id', $invoice->id)
            ->whereIn('status', [RefundStatus::Pending->value, RefundStatus::Approved->value])
            ->get();

        foreach ($pending as $refund) {
            $this->refunds->reject($refund, Actor::system(), __('billing.refund.note.reinstated'));
        }
    }
}
