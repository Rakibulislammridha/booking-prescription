<?php

declare(strict_types=1);

namespace App\Domain\Billing\Listeners;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Billing\Services\RefundEligibility;
use App\Domain\Serials\Events\SerialNoShow;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Invoice;

/**
 * ARCHITECTURE §5.4 — Billing's consumer of `SerialNoShow`. A no-show keeps the fee: the slot was held and the
 * session's capacity was spent. The exception is `session_closed`, where the clinic never called the patient —
 * that is the clinic's fault, and the invoice is marked refund-eligible for a human to act on.
 *
 * As with cancellation, no money moves here. A background listener must never empty a drawer.
 */
final class RecordNoShowOnInvoice
{
    public function __construct(
        private readonly RefundEligibility $eligibility,
        private readonly AuditRecorder $audit,
    ) {}

    public function handle(SerialNoShow $event): void
    {
        $appointment = Appointment::query()->where('serial_id', $event->serialId)->first();

        if ($appointment === null) {
            return;
        }

        $invoice = Invoice::query()->where('appointment_id', $appointment->id)->live()->first();

        if ($invoice === null || $invoice->paid_paisa === 0) {
            return;
        }

        $decision = $this->eligibility->forNoShow($appointment, $event->reason);

        $this->audit->record(AuditAction::Refund, $invoice, null, [
            'refund_eligible' => $decision->eligible,
            'reason_code' => $decision->reasonCode->value,
            'paid_paisa' => $invoice->paid_paisa,
        ], ['event' => 'no_show_decision', 'no_show_reason' => $event->reason, 'explanation' => $decision->explanation]);
    }
}
