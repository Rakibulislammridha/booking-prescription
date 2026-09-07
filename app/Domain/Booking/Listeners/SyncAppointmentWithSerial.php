<?php

declare(strict_types=1);

namespace App\Domain\Booking\Listeners;

use App\Domain\Booking\Enums\AppointmentStatus;
use App\Domain\Serials\Enums\CancelReason;
use App\Domain\Serials\Enums\SerialStatus;
use App\Domain\Serials\Events\SerialStatusChanged;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Serial;

/**
 * appointments.status mirrors the serial state machine (AppointmentStatus::fromSerial). A transfer's cancellation
 * (reason `transferred`) is skipped — RepointAppointmentOnTransfer moves the appointment to the new serial instead.
 */
final class SyncAppointmentWithSerial
{
    public function handle(SerialStatusChanged $event): void
    {
        $appointmentId = $event->serial['appointment_id'] ?? null;

        if (! is_int($appointmentId)) {
            return;
        }

        $to = SerialStatus::tryFrom($event->to);
        $appointment = Appointment::query()->find($appointmentId);

        if ($to === null || $appointment === null || $appointment->status === AppointmentStatus::Draft) {
            return;
        }

        $serial = Serial::query()->find($event->serialId);

        if ($to === SerialStatus::Cancelled && $serial?->cancel_reason_code === CancelReason::Transferred) {
            return;
        }

        $columns = ['status' => AppointmentStatus::fromSerial($to)];

        if ($to === SerialStatus::Cancelled) {
            $columns += ['cancel_reason_code' => $serial->cancel_reason_code ?? CancelReason::Other, 'cancelled_at' => $serial->cancelled_at ?? now(), 'cancelled_by_user_id' => $event->actor?->userId];
        }

        $appointment->forceFill($columns)->save();
    }
}
