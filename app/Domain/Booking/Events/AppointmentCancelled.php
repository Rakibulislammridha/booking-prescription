<?php

declare(strict_types=1);

namespace App\Domain\Booking\Events;

use App\Models\Tenant\Appointment;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** Cancelled from the desk/site; `refundEligible` mirrors SerialCancelled (billing decides the money). */
final class AppointmentCancelled implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public readonly int $appointmentId;

    public readonly string $appointmentPublicId;

    public function __construct(Appointment $appointment, public readonly string $reasonCode, public readonly bool $refundEligible)
    {
        $this->appointmentId = $appointment->id;
        $this->appointmentPublicId = $appointment->public_id;
    }
}
