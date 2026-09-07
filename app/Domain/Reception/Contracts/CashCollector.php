<?php

declare(strict_types=1);

namespace App\Domain\Reception\Contracts;

use App\Domain\Reception\Data\CashCollection;
use App\Domain\Shared\Actor;
use App\Models\Tenant\Appointment;
use Carbon\CarbonImmutable;

/**
 * "Collect fee" at the desk (BRIEF §5.F) — `payments`, `invoices` and `cash_shifts` are Billing's tables (SCHEMA §3.5).
 * Until Billing rebinds this contract, NullCashCollector updates appointments.payment_status only and the receipt
 * number is the desk's (`D{device.number}-000123` offline, `C-{appointment}` online).
 */
interface CashCollector
{
    /**
     * `$clientEventId` is the device's ULID for the originating offline event (`payments.client_event_id`,
     * SCHEMA §3.5) and is null for an online collection. It is NOT the idempotency handle — the receipt number is,
     * and the double-charge guarantee rests on `payments.idempotency_key`/`receipt_number` being UNIQUE — but
     * SCHEMA reserves the column so a payment can be traced back to the event log entry that produced it, and to
     * the partial UNIQUE (reception_device_id, client_event_id) that catches a replay from a second angle.
     */
    public function collect(Appointment $appointment, int $amountPaisa, string $receiptNo, Actor $actor, ?CarbonImmutable $collectedAt = null, ?string $note = null, ?string $clientEventId = null): CashCollection;

    /** Was this receipt already recorded for the appointment? (replay idempotency — checked before the "already paid" rule) */
    public function wasRecorded(Appointment $appointment, string $receiptNo): bool;
}
