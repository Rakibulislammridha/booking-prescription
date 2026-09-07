<?php

declare(strict_types=1);

namespace App\Domain\Reception\Services;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Booking\Enums\PaymentStatus;
use App\Domain\Reception\Contracts\CashCollector;
use App\Domain\Reception\Data\CashCollection;
use App\Domain\Shared\Actor;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\AuditLog;
use Carbon\CarbonImmutable;

/**
 * No payments table yet: the cash is a fact recorded on the appointment (payment_status paid|partial) and in
 * audit_logs (amount, receipt, collected_at, actor). Idempotent per receipt number: replaying the same receipt
 * returns the same collection with `duplicate = true` instead of double-counting.
 */
final class NullCashCollector implements CashCollector
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function collect(Appointment $appointment, int $amountPaisa, string $receiptNo, Actor $actor, ?CarbonImmutable $collectedAt = null, ?string $note = null, ?string $clientEventId = null): CashCollection
    {
        $amountPaisa = max(0, $amountPaisa);
        $already = $this->wasRecorded($appointment, $receiptNo);

        if ($already) {
            return new CashCollection(null, $receiptNo, $amountPaisa, $appointment->payment_status, duplicate: true);
        }

        $status = $amountPaisa >= $appointment->fee_paisa ? PaymentStatus::Paid : ($amountPaisa > 0 ? PaymentStatus::Partial : $appointment->payment_status);
        $appointment->forceFill(['payment_status' => $status])->save();

        $this->audit->record(AuditAction::Update, $appointment, null, [
            'cash_collected_paisa' => $amountPaisa,
            'receipt_no' => $receiptNo,
            'collected_at' => ($collectedAt ?? now())->toIso8601String(),
            'note' => $note,
            'payment_status' => $status->value,
        ], ['actor_user_id' => $actor->userId, 'actor_source' => $actor->source, 'client_event_id' => $clientEventId, 'collector' => 'null']);

        return new CashCollection(null, $receiptNo, $amountPaisa, $status);
    }

    public function wasRecorded(Appointment $appointment, string $receiptNo): bool
    {
        return AuditLog::query()
            ->where('auditable_type', $appointment->getMorphClass())
            ->where('auditable_id', $appointment->id)
            ->where('action', AuditAction::Update->value)
            ->where('after->receipt_no', $receiptNo)
            ->exists();
    }
}
