<?php

declare(strict_types=1);

namespace App\Domain\Booking\Listeners;

use App\Domain\Booking\Enums\AppointmentStatus;
use App\Domain\Booking\Enums\AppointmentType;
use App\Domain\Booking\Enums\BookingChannel;
use App\Domain\Booking\Enums\FeeRule;
use App\Domain\Booking\Enums\PaymentStatus;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Branch;
use App\Models\Tenant\Doctor;

/**
 * PRESCRIPTION.md §4.8 / ARCHITECTURE §5.4: `App\Domain\Prescription\Events\FollowUpScheduled` → a `draft` appointment
 * (type + channel followup, follow_up_of_visit_id, scheduled_date = the preferred date, NO serial). It becomes a
 * serial only through RebookFollowUp. Registered by event class *name* (BookingServiceProvider) because the
 * Prescription module is built concurrently — the handler reads the documented public properties.
 */
final class CreateDraftFollowUpAppointment
{
    public const EVENT = 'App\\Domain\\Prescription\\Events\\FollowUpScheduled';

    public function handle(object $event): void
    {
        if (! (bool) ($event->createBooking ?? true)) {
            return;
        }

        $visitId = (int) ($event->visitId ?? 0);
        $patientId = (int) ($event->patientId ?? 0);
        $doctorId = (int) ($event->doctorId ?? 0);

        if ($visitId === 0 || $patientId === 0 || $doctorId === 0) {
            return;
        }

        if (Appointment::query()->where('follow_up_of_visit_id', $visitId)->where('status', AppointmentStatus::Draft->value)->exists()) {
            return;   // idempotent
        }

        $doctor = Doctor::query()->with('profile')->find($doctorId);

        if ($doctor === null) {
            return;
        }

        $branchId = isset($event->branchId) && is_int($event->branchId) ? $event->branchId : (int) Branch::query()->active()->orderByDesc('is_main')->orderBy('id')->value('id');
        $listFee = (int) ($doctor->profile->new_fee_paisa ?? 0);

        Appointment::query()->create([
            'patient_id' => $patientId,
            'doctor_id' => $doctor->id,
            'branch_id' => $branchId,
            'session_instance_id' => null,
            'type' => AppointmentType::Followup,
            'channel' => BookingChannel::Followup,
            'status' => AppointmentStatus::Draft,
            'scheduled_date' => (string) ($event->followUpDate ?? ''),
            'list_fee_paisa' => $listFee,
            'fee_paisa' => (int) ($doctor->profile->followup_fee_paisa ?? $listFee),
            'fee_rule' => FeeRule::FollowupPaid,
            'fee_rule_reason' => __('booking.fee.reason.draft'),
            'payment_status' => PaymentStatus::Unpaid,
            'follow_up_of_visit_id' => $visitId,
            'notes' => isset($event->note) && is_string($event->note) ? mb_substr($event->note, 0, 500) : null,
            'booked_by_patient' => false,
        ]);
    }
}
