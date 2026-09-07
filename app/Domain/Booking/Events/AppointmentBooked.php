<?php

declare(strict_types=1);

namespace App\Domain\Booking\Events;

use App\Domain\Booking\Enums\BookingChannel;
use App\Domain\Serials\Events\SerialEvent;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Serial;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * ARCHITECTURE §5.4: consumed by SaaS (usage `appointments`), Billing (free follow-up window / invoice) and
 * Notifications (booking confirmed). Payload = ids + frozen snapshots; `previousVisitId` stays null until the
 * Prescription module's `visits` exist — `previousAppointmentId` carries the follow-up reference meanwhile.
 */
final class AppointmentBooked implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public readonly int $appointmentId;

    public readonly string $appointmentPublicId;

    /** @var array<string, mixed> */
    public readonly array $appointment;

    /** @var array<string, mixed> */
    public readonly array $serial;

    public function __construct(Appointment $appointment, Serial $serial, public readonly BookingChannel $channel, public readonly ?int $previousVisitId = null, public readonly ?int $previousAppointmentId = null)
    {
        $this->appointmentId = $appointment->id;
        $this->appointmentPublicId = $appointment->public_id;
        $this->appointment = [
            'id' => $appointment->id,
            'public_id' => $appointment->public_id,
            'patient_id' => $appointment->patient_id,
            'doctor_id' => $appointment->doctor_id,
            'branch_id' => $appointment->branch_id,
            'session_instance_id' => $appointment->session_instance_id,
            'type' => $appointment->type->value,
            'channel' => $appointment->channel->value,
            'status' => $appointment->status->value,
            'scheduled_date' => $appointment->scheduled_date?->toDateString(),
            'fee_paisa' => $appointment->fee_paisa,
            'fee_rule' => $appointment->fee_rule->value,
        ];
        $this->serial = SerialEvent::snapshot($serial);
    }
}
