<?php

declare(strict_types=1);

namespace App\Domain\Billing\Listeners;

use App\Domain\Billing\Actions\CreateInvoiceForAppointment;
use App\Domain\Booking\Events\AppointmentBooked;
use App\Domain\Shared\Actor;
use App\Models\Tenant\Appointment;

/**
 * ARCHITECTURE §5.4 — Billing's consumer of `AppointmentBooked`. Every booking gets its draft invoice with the
 * consultation line copied from the appointment's FEE SNAPSHOT, so the free follow-up window Booking's
 * FeeResolver decided (SCHEMA §5.10) is what the bill and the money receipt say. Billing never re-derives a fee.
 *
 * Deviation from ARCHITECTURE §5.4, which names `CreateInvoiceLineForSerial` on `SerialAllocated`: the invoice
 * keys on the appointment (`invoices.appointment_id` is where the partial unique index lives) and only the
 * appointment carries `fee_paisa`/`fee_rule`, so `AppointmentBooked` — dispatched immediately after, with the
 * snapshot in hand — is the correct hook. A serial without an appointment has no fee to bill.
 */
final class CreateInvoiceForBooking
{
    public function __construct(private readonly CreateInvoiceForAppointment $invoices) {}

    public function handle(AppointmentBooked $event): void
    {
        $appointment = Appointment::query()->find($event->appointmentId);

        if ($appointment === null) {
            return;
        }

        $this->invoices->handle($appointment, Actor::system());
    }
}
