<?php

declare(strict_types=1);

namespace App\Domain\Booking\Contracts;

use App\Domain\Shared\Actor;
use App\Models\Tenant\Appointment;

/**
 * Money movements are Billing's (SERIAL_ENGINE §15 "revenue neutrality"). Booking/Reception only report the facts
 * through this contract: a cancellation with its refund eligibility, a cash refund decided in an offline-sync
 * resolution, or a credit. The null implementation records nothing but the audit trail.
 */
interface RefundProcessor
{
    /** @return array<string, mixed> billing's view of what happened (empty for the null implementation) */
    public function onCancellation(Appointment $appointment, bool $refundEligible, Actor $actor): array;

    /**
     * OFFLINE §8.5 `refund_cash`: cash taken for an already paid appointment is handed back now.
     *
     * @return array<string, mixed>
     */
    public function refundCash(Appointment $appointment, int $amountPaisa, string $receiptNo, Actor $actor): array;

    /**
     * OFFLINE §8.5 `credit`: keep the cash as patient credit.
     *
     * @return array<string, mixed>
     */
    public function credit(Appointment $appointment, int $amountPaisa, string $receiptNo, Actor $actor): array;
}
