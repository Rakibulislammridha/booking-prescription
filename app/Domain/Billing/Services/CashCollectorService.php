<?php

declare(strict_types=1);

namespace App\Domain\Billing\Services;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Billing\Actions\RecordCashPayment;
use App\Domain\Billing\Enums\PaymentMethod;
use App\Domain\Reception\Contracts\CashCollector;
use App\Domain\Reception\Data\CashCollection;
use App\Domain\Shared\Actor;
use App\Models\Tenant\Appointment;
use Carbon\CarbonImmutable;

/**
 * The real implementation of the Reception seam (`App\Domain\Reception\Contracts\CashCollector`), replacing
 * `NullCashCollector`: the desk's "collect fee" button and the offline `collect_cash` replay now write real
 * `invoices` / `invoice_items` / `payments` rows instead of an audit note.
 *
 * The contract hands us a receipt number, and that is the idempotency handle: online the desk mints
 * `C-{appointment}` and offline the device mints `D{n}-{seq}`, unique clinic-wide by construction
 * (OFFLINE §6.1). It becomes `payments.idempotency_key` AND `payments.receipt_number`, both UNIQUE, so a
 * replayed offline event finds the existing row and returns `duplicate = true` — no second charge, ever.
 */
final class CashCollectorService implements CashCollector
{
    public function __construct(
        private readonly RecordCashPayment $cash,
        private readonly AuditRecorder $audit,
    ) {}

    public function collect(Appointment $appointment, int $amountPaisa, string $receiptNo, Actor $actor, ?CarbonImmutable $collectedAt = null, ?string $note = null, ?string $clientEventId = null): CashCollection
    {
        $result = $this->cash->handle(
            appointment: $appointment,
            amountPaisa: max(0, $amountPaisa),
            receiptNo: $receiptNo,
            actor: $actor,
            method: PaymentMethod::Cash,
            collectedAt: $collectedAt,
            clientEventId: $clientEventId,
            receptionDeviceId: $actor->deviceId,
        );

        $status = $appointment->refresh()->payment_status;

        // The money movement is also audited ON THE APPOINTMENT (BRIEF §8), in the shape Reception's
        // ShiftSummary already reads, so the desk's shift screen keeps working while Billing's own
        // cash_shifts reconciliation takes over.
        if (! $result->duplicate) {
            $this->audit->record(AuditAction::Update, $appointment, null, [
                'cash_collected_paisa' => $result->payment->amount_paisa,
                'receipt_no' => $result->payment->receipt_number ?? $receiptNo,
                'collected_at' => ($collectedAt ?? now())->toIso8601String(),
                'note' => $note,
                'payment_status' => $status->value,
            ], [
                'actor_user_id' => $actor->userId,
                'actor_source' => $actor->source,
                'client_event_id' => $clientEventId,
                'collector' => 'billing',
                'invoice' => $result->invoice->public_id,
                'payment' => $result->payment->public_id,
            ]);
        }

        return new CashCollection(
            paymentPublicId: $result->payment->public_id,
            receiptNo: $result->payment->receipt_number ?? $receiptNo,
            amountPaisa: $result->payment->amount_paisa,
            paymentStatus: $status,
            duplicate: $result->duplicate,
        );
    }

    public function wasRecorded(Appointment $appointment, string $receiptNo): bool
    {
        return $this->cash->wasRecorded($receiptNo);
    }
}
