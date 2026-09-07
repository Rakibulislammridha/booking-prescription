<?php

declare(strict_types=1);

namespace App\Domain\Booking\Data;

use App\Models\Tenant\Appointment;
use App\Models\Tenant\Patient;
use App\Models\Tenant\Serial;

/** Outcome of BookAppointment: the appointment, its serial and the (possibly newly created) patient. */
final readonly class BookingResult
{
    public function __construct(
        public Appointment $appointment,
        public Serial $serial,
        public Patient $patient,
        public bool $patientCreated,
        public FeeDecision $fee,
        public bool $replayed = false,   // an idempotent re-submit returned the existing booking
    ) {}
}
