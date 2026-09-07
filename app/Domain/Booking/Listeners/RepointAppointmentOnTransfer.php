<?php

declare(strict_types=1);

namespace App\Domain\Booking\Listeners;

use App\Domain\Booking\Enums\AppointmentStatus;
use App\Domain\Booking\Services\FeeResolver;
use App\Domain\Serials\Events\SerialTransferred;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\Serial;
use App\Models\Tenant\SessionInstance;

/**
 * SERIAL_ENGINE §9.2: the old serial is cancelled (reason transferred), the new one belongs to another doctor —
 * the appointment moves with it and gets a fresh fee snapshot for the new doctor (SCHEMA §5.10). Idempotent.
 */
final class RepointAppointmentOnTransfer
{
    public function __construct(private readonly FeeResolver $fees) {}

    public function handle(SerialTransferred $event): void
    {
        $appointmentId = $event->old['appointment_id'] ?? null;

        if (! is_int($appointmentId)) {
            return;
        }

        $appointment = Appointment::query()->with('patient')->find($appointmentId);
        $session = SessionInstance::query()->find($event->new['session_instance_id']);
        $new = Serial::query()->find($event->new['id']);

        if ($appointment === null || $session === null || $new === null) {
            return;
        }

        /** @var Doctor $doctor */
        $doctor = Doctor::query()->with('profile')->findOrFail($session->doctor_id);
        $fee = $this->fees->resolve($appointment->patient, $doctor, $session, $appointment->channel, $appointment->type);

        $appointment->forceFill([
            'session_instance_id' => $session->id,
            'serial_id' => $new->id,
            'doctor_id' => $session->doctor_id,
            'branch_id' => $session->branch_id,
            'scheduled_date' => $session->session_date->toDateString(),
            'status' => AppointmentStatus::Confirmed,
            'cancel_reason_code' => null,
            'cancelled_at' => null,
        ] + $fee->toColumns())->save();

        if ($new->appointment_id !== $appointment->id) {
            $new->forceFill(['appointment_id' => $appointment->id])->save();
        }
    }
}
