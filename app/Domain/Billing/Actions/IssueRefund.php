<?php

declare(strict_types=1);

namespace App\Domain\Billing\Actions;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Billing\Data\RefundRequest;
use App\Domain\Billing\Enums\PaymentTxnStatus;
use App\Domain\Billing\Enums\RefundStatus;
use App\Domain\Billing\Events\RefundIssued;
use App\Domain\Billing\Exceptions\RefundExceedsPayment;
use App\Domain\Billing\Exceptions\RefundNotPending;
use App\Domain\Billing\Exceptions\RefundsAreOnlineOnly;
use App\Domain\Billing\Services\CurrentShift;
use App\Domain\Billing\Services\InvoiceLedger;
use App\Domain\Billing\Services\Paisa;
use App\Domain\Shared\Actor;
use App\Models\Tenant\Invoice;
use App\Models\Tenant\Payment;
use App\Models\Tenant\Refund;
use Illuminate\Support\Facades\DB;

/**
 * Money back, against one payment, with a reason code (BRIEF §5.F, SCHEMA §3.5).
 *
 *  - The amount may never exceed what is left of that payment after earlier refunds AND still-open claims, so
 *    two half-refunds cannot together exceed the payment (`RefundExceedsPayment`).
 *  - Partial refunds are first-class: the payment's status becomes `partially_refunded` and its
 *    `refunded_paisa` is recomputed from the refund rows, never incremented.
 *  - A refund is a NEW row; the payment it reverses is never edited down or deleted.
 *  - Reception devices may never refund (OFFLINE §6.2) — an offline-replay actor is rejected outright.
 *  - Cash refunds are attached to the refunding user's open shift so the drawer reconciles.
 */
final class IssueRefund
{
    public function __construct(
        private readonly InvoiceLedger $ledger,
        private readonly CurrentShift $shifts,
        private readonly AuditRecorder $audit,
    ) {}

    public function handle(Payment $payment, RefundRequest $request, Actor $actor): Refund
    {
        if ($actor->source === 'offline_replay' || ($actor->deviceId !== null && $actor->userId === null)) {
            throw new RefundsAreOnlineOnly;
        }

        $refund = DB::transaction(function () use ($payment, $request, $actor): Refund {
            /** @var Payment $lockedPayment */
            $lockedPayment = Payment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            /** @var Invoice $invoice */
            $invoice = Invoice::query()->whereKey($lockedPayment->invoice_id)->lockForUpdate()->firstOrFail();

            $refundable = $this->ledger->refundablePaisa($lockedPayment);
            $amount = $request->amountPaisa ?? $refundable;

            if ($amount <= 0 || $amount > $refundable) {
                throw new RefundExceedsPayment([
                    'amount' => Paisa::toDecimal(max(0, $amount)),
                    'refundable' => Paisa::toDecimal($refundable),
                ]);
            }

            $method = $request->method ?? $lockedPayment->method;
            $processed = $request->autoProcess;

            $refund = new Refund;
            $refund->forceFill([
                'payment_id' => $lockedPayment->id,
                'invoice_id' => $invoice->id,
                'amount_paisa' => $amount,
                'method' => $method,
                'status' => $processed ? RefundStatus::Processed : RefundStatus::Pending,
                'reason_code' => $request->reasonCode,
                'reason_note' => $request->note,
                'requested_by_user_id' => $actor->userId,
                'approved_by_user_id' => $processed ? $actor->userId : null,
                'cash_shift_id' => $method->movesCashDrawer() ? $this->shifts->idForUser($actor->userId) : null,
                'processed_at' => $processed ? now() : null,
            ])->save();

            if ($processed) {
                $this->settle($lockedPayment, $invoice);
            }

            $this->audit->record(AuditAction::Refund, $refund, null, [
                'payment_id' => $lockedPayment->id,
                'invoice_id' => $invoice->id,
                'amount_paisa' => $amount,
                'method' => $method->value,
                'reason_code' => $request->reasonCode->value,
                'status' => $refund->status->value,
            ], ['actor_user_id' => $actor->userId, 'actor_source' => $actor->source]);

            return $refund;
        });

        DB::afterCommit(fn () => RefundIssued::dispatch($refund));

        return $refund;
    }

    /** Approve or process a pending refund (the two-step flow when `autoProcess` was false). */
    public function process(Refund $refund, Actor $actor): Refund
    {
        $processed = DB::transaction(function () use ($refund, $actor): Refund {
            /** @var Refund $locked */
            $locked = Refund::query()->whereKey($refund->id)->lockForUpdate()->firstOrFail();

            if (! $locked->status->isOpen()) {
                throw new RefundNotPending(['status' => $locked->status->value]);
            }

            /** @var Payment $payment */
            $payment = Payment::query()->whereKey($locked->payment_id)->lockForUpdate()->firstOrFail();
            /** @var Invoice $invoice */
            $invoice = Invoice::query()->whereKey($locked->invoice_id)->lockForUpdate()->firstOrFail();

            $locked->forceFill([
                'status' => RefundStatus::Processed,
                'approved_by_user_id' => $locked->approved_by_user_id ?? $actor->userId,
                'processed_at' => now(),
                'cash_shift_id' => $locked->cash_shift_id ?? ($locked->method->movesCashDrawer() ? $this->shifts->idForUser($actor->userId) : null),
            ])->save();

            $this->settle($payment, $invoice);

            $this->audit->record(AuditAction::Refund, $locked, null, ['status' => RefundStatus::Processed->value, 'amount_paisa' => $locked->amount_paisa], ['actor_user_id' => $actor->userId, 'actor_source' => $actor->source, 'event' => 'refund_processed']);

            return $locked;
        });

        DB::afterCommit(fn () => RefundIssued::dispatch($processed));

        return $processed;
    }

    public function reject(Refund $refund, Actor $actor, ?string $note = null): Refund
    {
        return DB::transaction(function () use ($refund, $actor, $note): Refund {
            /** @var Refund $locked */
            $locked = Refund::query()->whereKey($refund->id)->lockForUpdate()->firstOrFail();

            if (! $locked->status->isOpen()) {
                throw new RefundNotPending(['status' => $locked->status->value]);
            }

            $locked->forceFill(['status' => RefundStatus::Rejected, 'reason_note' => $note ?? $locked->reason_note, 'approved_by_user_id' => $actor->userId])->save();

            $this->audit->record(AuditAction::Refund, $locked, null, ['status' => RefundStatus::Rejected->value], ['actor_user_id' => $actor->userId, 'actor_source' => $actor->source, 'event' => 'refund_rejected']);

            return $locked;
        });
    }

    /** Recompute the payment's refunded total and status from the refund rows, then resync the invoice. */
    private function settle(Payment $payment, Invoice $invoice): void
    {
        $refunded = (int) Refund::query()
            ->where('payment_id', $payment->id)
            ->where('status', RefundStatus::Processed->value)
            ->sum('amount_paisa');

        $payment->forceFill([
            'refunded_paisa' => min($refunded, $payment->amount_paisa),
            'status' => match (true) {
                $refunded >= $payment->amount_paisa => PaymentTxnStatus::Refunded,
                $refunded > 0 => PaymentTxnStatus::PartiallyRefunded,
                default => $payment->status,
            },
        ])->save();

        $this->ledger->sync($invoice->refresh());
    }
}
