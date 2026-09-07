<?php

declare(strict_types=1);

namespace App\Domain\Billing\Listeners;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Billing\Actions\VoidInvoice;
use App\Domain\Billing\Services\RefundEligibility;
use App\Domain\Serials\Enums\CancelReason;
use App\Domain\Serials\Events\SerialCancelled;
use App\Domain\Shared\Actor;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Invoice;

/**
 * ARCHITECTURE §5.4 — Billing's consumer of `SerialCancelled`. The engine already decided `refundEligible` from
 * `serial.cancel_cutoff_minutes`; Billing records that decision on the invoice and, when nothing was ever paid,
 * voids the draft bill so a cancelled booking does not sit in the dues report forever.
 *
 * Money is NOT moved here: a refund of settled money is raised by `CancelAppointment` through the
 * `RefundProcessor` seam (a `pending` refunds row a human must approve), so a background listener can never
 * empty a drawer.
 */
final class DecideRefundOnCancellation
{
    public function __construct(
        private readonly RefundEligibility $eligibility,
        private readonly VoidInvoice $void,
        private readonly AuditRecorder $audit,
    ) {}

    public function handle(SerialCancelled $event): void
    {
        $appointment = Appointment::query()->where('serial_id', $event->serialId)->first();

        if ($appointment === null) {
            return;
        }

        $invoice = Invoice::query()->where('appointment_id', $appointment->id)->live()->first();

        if ($invoice === null) {
            return;
        }

        $decision = $this->eligibility->forCancellation(
            $appointment,
            CancelReason::tryFrom($event->reasonCode) ?? CancelReason::Other,
            $event->cancelledByRole === 'patient',
        );

        $this->audit->record(AuditAction::Refund, $invoice, null, [
            'refund_eligible' => $event->refundEligible,
            'reason_code' => $decision->reasonCode->value,
            'minutes_before_start' => $event->minutesBeforePlannedStart,
            'paid_paisa' => $invoice->paid_paisa,
        ], ['event' => 'cancellation_decision', 'explanation' => $decision->explanation]);

        // Nothing was collected: the draft/issued bill is void, not a due.
        if ($invoice->paid_paisa === 0 && ! $invoice->payments()->settled()->exists()) {
            $this->void->handle($invoice, __('billing.void.reason.cancelled'), Actor::system());
        }
    }
}
