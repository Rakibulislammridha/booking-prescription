<?php

declare(strict_types=1);

namespace Tests\Feature\Billing\Concerns;

use App\Domain\Billing\Actions\IssueInvoice;
use App\Domain\Booking\Actions\BookAppointment;
use App\Domain\Booking\Data\BookingRequest;
use App\Domain\Booking\Data\BookingResult;
use App\Domain\Booking\Enums\BookingChannel;
use App\Domain\Shared\Actor;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\Invoice;
use App\Models\Tenant\SessionInstance;
use Tests\Feature\Serials\Concerns\SerialFixtures;

/** Shared billing fixture: a booked appointment (which already carries its fee snapshot) and its invoice. */
trait BillingFixtures
{
    use SerialFixtures;

    protected function staffActor(): Actor
    {
        return Actor::user((int) (auth('web')->id() ?? 0));
    }

    protected function book(?SessionInstance $session = null, string $mobile = '01710000001', string $name = 'Rahima Begum'): BookingResult
    {
        $session ??= $this->openSession();

        return app(BookAppointment::class)->handle(
            new BookingRequest(channel: BookingChannel::Counter, mobile: $mobile, name: $name, sessionPublicId: $session->public_id),
            $this->staffActor(),
        );
    }

    /** The invoice the AppointmentBooked listener created, issued and ready to take money. */
    protected function issuedInvoiceFor(Appointment $appointment): Invoice
    {
        $invoice = Invoice::query()->where('appointment_id', $appointment->id)->live()->firstOrFail();

        return $invoice->isDraft()
            ? app(IssueInvoice::class)->handle($invoice, $this->staffActor())
            : $invoice;
    }

    protected function doctorOf(SessionInstance $session): Doctor
    {
        return Doctor::query()->findOrFail($session->doctor_id);
    }
}
