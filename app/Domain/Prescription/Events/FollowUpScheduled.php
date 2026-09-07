<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** PRESCRIPTION.md §4.8 — Booking owns CreateDraftFollowUpAppointment; Notifications the reminder. */
final class FollowUpScheduled implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $tenantId,
        public readonly int $prescriptionId,
        public readonly int $visitId,
        public readonly int $patientId,
        public readonly int $doctorId,
        public readonly ?int $branchId,
        public readonly string $followUpDate,           // Y-m-d, tenant tz
        public readonly ?string $note,
        public readonly bool $createBooking,
    ) {}
}
