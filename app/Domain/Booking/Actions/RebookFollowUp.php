<?php

declare(strict_types=1);

namespace App\Domain\Booking\Actions;

use App\Domain\Booking\Data\BookingRequest;
use App\Domain\Booking\Data\BookingResult;
use App\Domain\Booking\Enums\AppointmentStatus;
use App\Domain\Booking\Enums\AppointmentType;
use App\Domain\Booking\Enums\BookingChannel;
use App\Domain\Shared\Actor;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\SessionInstance;
use Illuminate\Support\Facades\DB;

/**
 * SERIAL_ENGINE §11.3: one-tap rebooking from a previous appointment (the visit timeline once P ships visits).
 * source `followup`, type `followup`, pool counter when staff act / online when the patient acts (BookingChannel).
 * A `draft` follow-up appointment (CreateDraftFollowUpAppointment) is consumed: it becomes the confirmed booking.
 */
final class RebookFollowUp
{
    public function __construct(private readonly BookAppointment $book) {}

    public function handle(Appointment $previous, SessionInstance $target, Actor $actor, ?string $clientEventId = null, ?string $notes = null): BookingResult
    {
        $previous->loadMissing('patient');

        $result = $this->book->handle(new BookingRequest(
            channel: BookingChannel::Followup,
            patientPublicId: $previous->patient->public_id,
            sessionPublicId: $target->public_id,
            type: AppointmentType::Followup,
            followUpOfAppointmentId: $previous->status === AppointmentStatus::Draft ? null : $previous->id,
            clientEventId: $clientEventId,
            notes: $notes,
            otpVerified: true,
        ), $actor);

        if ($previous->status === AppointmentStatus::Draft && ! $result->replayed) {
            DB::transaction(function () use ($previous, $result): void {
                $result->appointment->forceFill(['follow_up_of_visit_id' => $previous->follow_up_of_visit_id, 'notes' => $result->appointment->notes ?? $previous->notes])->save();
                $previous->delete();   // a draft has no session (CHECK appointments_draft_session_check), so it is removed, not cancelled
            });
        }

        return $result;
    }
}
