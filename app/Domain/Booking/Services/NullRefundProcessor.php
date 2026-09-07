<?php

declare(strict_types=1);

namespace App\Domain\Booking\Services;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Booking\Contracts\RefundProcessor;
use App\Domain\Booking\Enums\PaymentStatus;
use App\Domain\Shared\Actor;
use App\Models\Tenant\Appointment;

/** Until Billing ships: no money moves, the decision is audited on the appointment and its payment_status kept honest. */
final class NullRefundProcessor implements RefundProcessor
{
    public function __construct(private readonly AuditRecorder $audit) {}

    /** @return array<string, mixed> */
    public function onCancellation(Appointment $appointment, bool $refundEligible, Actor $actor): array
    {
        $this->audit->record(AuditAction::Refund, $appointment, null, ['decision' => $refundEligible ? 'refund_pending' : 'no_refund', 'payment_status' => $appointment->payment_status->value], ['actor_user_id' => $actor->userId, 'actor_source' => $actor->source, 'processor' => 'null']);

        return ['status' => $refundEligible && $appointment->payment_status !== PaymentStatus::Unpaid ? 'pending' : 'none', 'amount_paisa' => $refundEligible ? $appointment->fee_paisa : 0];
    }

    /** @return array<string, mixed> */
    public function refundCash(Appointment $appointment, int $amountPaisa, string $receiptNo, Actor $actor): array
    {
        $this->audit->record(AuditAction::Refund, $appointment, null, ['cash_refunded_paisa' => $amountPaisa, 'receipt_no' => $receiptNo], ['actor_user_id' => $actor->userId, 'actor_source' => $actor->source, 'processor' => 'null']);

        return ['status' => 'refunded', 'amount_paisa' => $amountPaisa, 'receipt_no' => $receiptNo];
    }

    /** @return array<string, mixed> */
    public function credit(Appointment $appointment, int $amountPaisa, string $receiptNo, Actor $actor): array
    {
        $this->audit->record(AuditAction::Update, $appointment, null, ['credit_paisa' => $amountPaisa, 'receipt_no' => $receiptNo], ['actor_user_id' => $actor->userId, 'actor_source' => $actor->source, 'processor' => 'null']);

        return ['status' => 'credited', 'amount_paisa' => $amountPaisa, 'receipt_no' => $receiptNo];
    }
}
