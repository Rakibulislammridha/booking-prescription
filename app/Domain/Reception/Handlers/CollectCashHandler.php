<?php

declare(strict_types=1);

namespace App\Domain\Reception\Handlers;

use App\Domain\Booking\Contracts\RefundProcessor;
use App\Domain\Booking\Enums\PaymentStatus;
use App\Domain\Reception\Contracts\CashCollector;
use App\Domain\Reception\Enums\ConflictReason;
use App\Domain\Reception\Enums\ConflictResolution;
use App\Domain\Reception\Sync\ReplayContext;
use App\Domain\Reception\Sync\ReplayHandler;
use App\Domain\Reception\Sync\ReplayOutcome;
use App\Domain\Reception\Sync\Resolution;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\OfflineEvent;

/**
 * OFFLINE §7.2 / §8.5: cash taken during an outage is a fact → CashCollector (Billing's RecordCashPayment once it
 * ships). An appointment already fully paid is `already_paid`: refund_cash {} · credit {} · discard {reason} (HA).
 * Idempotent per receipt number.
 */
final class CollectCashHandler implements ReplayHandler
{
    public function __construct(private readonly CashCollector $collector, private readonly RefundProcessor $refunds) {}

    public function handle(OfflineEvent $event, ReplayContext $ctx, ?Resolution $resolution = null): ReplayOutcome
    {
        $p = $event->payload;
        $serial = $ctx->serialRef(isset($p['serialRef']) ? (string) $p['serialRef'] : null);

        if ($serial === null) {
            return ReplayOutcome::rejected('serial_not_found', __('reception.sync.serial_not_found'));
        }

        $appointment = $serial->appointment_id === null ? null : Appointment::query()->find($serial->appointment_id);
        $amount = (int) ($p['amount'] ?? 0);
        $receiptNo = trim((string) ($p['receiptNo'] ?? ''));

        if ($appointment === null || $receiptNo === '' || $amount < 0) {
            return ReplayOutcome::rejected('payload_invalid', __('reception.sync.payload_invalid'), $serial->session_instance_id);
        }

        if ($resolution !== null) {
            return match ($resolution->resolution) {
                ConflictResolution::RefundCash => ReplayOutcome::accepted(['refund' => $this->refunds->refundCash($appointment, $amount, $receiptNo, $ctx->actorDto), 'cash' => ['amount' => $amount, 'receipt_no' => $receiptNo]], $serial->session_instance_id),
                ConflictResolution::Credit => ReplayOutcome::accepted(['credit' => $this->refunds->credit($appointment, $amount, $receiptNo, $ctx->actorDto), 'cash' => ['amount' => $amount, 'receipt_no' => $receiptNo]], $serial->session_instance_id),
                default => ReplayOutcome::rejected('discarded', (string) ($resolution->string('reason') ?? __('reception.sync.discarded')), $serial->session_instance_id),
            };
        }

        if ($this->collector->wasRecorded($appointment, $receiptNo)) {
            return ReplayOutcome::accepted(['payment' => ['public_id' => null, 'receipt_no' => $receiptNo, 'amount_paisa' => $amount, 'payment_status' => $appointment->payment_status->value, 'duplicate' => true], 'noop' => true], $serial->session_instance_id);
        }

        if ($appointment->payment_status === PaymentStatus::Paid) {
            return ReplayOutcome::conflict(ConflictReason::AlreadyPaid, [
                'display_code' => $serial->display_code,
                'online_payment' => ['method' => null, 'amount' => $appointment->fee_paisa, 'paid_at' => null, 'txn_ref' => null, 'payment_status' => $appointment->payment_status->value],
                'cash' => ['amount' => $amount, 'receipt_no' => $receiptNo],
            ], $serial->session_instance_id);
        }

        // The device's own event id lands on `payments.client_event_id` (SCHEMA §3.5): the receipt number is what
        // makes the replay idempotent, this is what traces the row back to the event log entry.
        $collection = $this->collector->collect(
            $appointment,
            $amount,
            $receiptNo,
            $ctx->actorDto,
            $event->client_occurred_at,
            isset($p['note']) && is_string($p['note']) ? $p['note'] : null,
            $event->client_event_id,
        );

        return ReplayOutcome::accepted(['payment' => $collection->toArray(), 'noop' => $collection->duplicate], $serial->session_instance_id);
    }
}
