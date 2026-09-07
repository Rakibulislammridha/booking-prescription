<?php

declare(strict_types=1);

namespace App\Domain\Booking\Listeners;

use App\Domain\Booking\Enums\AppointmentStatus;
use App\Domain\Serials\Events\SerialPostponed;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Serial;
use App\Models\Tenant\SessionInstance;

/** SERIAL_ENGINE §9.1 step 3: the appointment follows the serial into the next session. Idempotent. */
final class RepointAppointmentOnPostpone
{
    public function handle(SerialPostponed $event): void
    {
        $appointmentId = $event->old['appointment_id'] ?? null;

        if (! is_int($appointmentId)) {
            return;
        }

        $appointment = Appointment::query()->find($appointmentId);
        $session = SessionInstance::query()->find($event->new['session_instance_id']);

        if ($appointment === null || $session === null) {
            return;
        }

        $appointment->forceFill([
            'session_instance_id' => $session->id,
            'serial_id' => $event->new['id'],
            'scheduled_date' => $session->session_date->toDateString(),
            'status' => AppointmentStatus::Confirmed,
        ])->save();

        $new = Serial::query()->find($event->new['id']);

        if ($new !== null && $new->appointment_id === null) {
            Serial::query()->where('appointment_id', $appointment->id)->whereKeyNot($new->id)->update(['appointment_id' => null]);
            $new->forceFill(['appointment_id' => $appointment->id])->save();
        }
    }
}
