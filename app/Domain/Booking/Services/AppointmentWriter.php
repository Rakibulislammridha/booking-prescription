<?php

declare(strict_types=1);

namespace App\Domain\Booking\Services;

use App\Domain\Booking\Data\FeeDecision;
use App\Domain\Booking\Enums\AppointmentStatus;
use App\Domain\Booking\Enums\BookingChannel;
use App\Domain\Booking\Enums\PaymentStatus;
use App\Domain\Booking\Events\AppointmentBooked;
use App\Domain\Shared\Actor;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Patient;
use App\Models\Tenant\Serial;
use App\Models\Tenant\SessionInstance;

/**
 * Writes the appointment row for an already allocated serial and links both directions in the same transaction
 * (SCHEMA §3.3: `serials.appointment_id` and `appointments.serial_id` must agree). Shared by BookAppointment (every
 * online channel) and the offline replay's IssueSerialHandler. Must be called inside the caller's transaction.
 *
 * `$status` is an explicit argument, not an `$extra` key, because a held booking (BRIEF §5.C advance payment) needs
 * `confirmed_at = null` and the `$extra` merge filters nulls out on purpose.
 *
 * @param  array<string, mixed>  $extra  notes, idempotency_key, client_event_id, reception_device_id, follow_up_of_visit_id, is_telemedicine
 */
final class AppointmentWriter
{
    /**
     * @param  array<string, mixed>  $extra  notes, idempotency_key, reception_device_id, follow_up_of_visit_id, is_telemedicine
     * @param  AppointmentStatus  $status  Confirmed for an ordinary booking; Pending for a serial held for advance payment
     */
    public function createForSerial(Serial $serial, Patient $patient, SessionInstance $session, BookingChannel $channel, FeeDecision $fee, Actor $actor, array $extra = [], AppointmentStatus $status = AppointmentStatus::Confirmed): Appointment
    {
        $appointment = Appointment::query()->create(array_merge([
            'patient_id' => $patient->id,
            'doctor_id' => $session->doctor_id,
            'branch_id' => $session->branch_id,
            'session_instance_id' => $session->id,
            'serial_id' => $serial->id,
            'channel' => $channel,
            'status' => $status,
            'scheduled_date' => $session->session_date->toDateString(),
            'slot_start_at' => $serial->slot_start_at,
            'payment_status' => PaymentStatus::Unpaid,
            'booked_by_user_id' => $actor->userId,
            'booked_by_patient' => $channel->isSelfService(),
            'is_telemedicine' => (bool) ($extra['is_telemedicine'] ?? ($channel === BookingChannel::Telemedicine)),
            'confirmed_at' => $status === AppointmentStatus::Confirmed ? now() : null,
            'client_event_id' => $serial->client_event_id,
            'reception_device_id' => $serial->reception_device_id,
        ], $fee->toColumns(), array_filter($extra, fn ($v) => $v !== null)));

        $serial->forceFill(['appointment_id' => $appointment->id])->save();

        AppointmentBooked::dispatch($appointment, $serial, $channel, null, $fee->previousAppointmentId);

        return $appointment;
    }
}
