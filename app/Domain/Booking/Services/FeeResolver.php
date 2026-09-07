<?php

declare(strict_types=1);

namespace App\Domain\Booking\Services;

use App\Domain\Booking\Data\FeeDecision;
use App\Domain\Booking\Data\FeeOverride;
use App\Domain\Booking\Enums\AppointmentStatus;
use App\Domain\Booking\Enums\AppointmentType;
use App\Domain\Booking\Enums\BookingChannel;
use App\Domain\Booking\Enums\FeeRule;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\Patient;
use App\Models\Tenant\SessionInstance;

/**
 * SCHEMA §5.10: the fee decision snapshotted onto appointments at booking. Rules come from doctor_profiles
 * (free_followup_within_days, followup_within_days, telemedicine_fee_paisa, online_booking_fee_delta_paisa); the
 * effective new/follow-up pair was snapshotted onto session_instances at materialisation.
 *
 * "Last visit" (L) is the patient's last COMPLETED appointment with the same doctor — until the Prescription module
 * ships `visits`, appointments are the only record of a consultation having happened (documented deviation; when
 * visits exist, `follow_up_of_visit_id` is written from the visit and this lookup switches to visits).
 */
final class FeeResolver
{
    public function resolve(
        Patient $patient,
        Doctor $doctor,
        SessionInstance $session,
        BookingChannel $channel,
        ?AppointmentType $forcedType = null,
        ?FeeOverride $override = null,
        ?Appointment $previous = null,
    ): FeeDecision {
        $profile = $doctor->profile;
        $freeDays = max(0, (int) ($profile->free_followup_within_days ?? 0));
        $windowDays = max(0, (int) ($profile->followup_within_days ?? 0));
        $listFee = $session->fee_new_paisa;
        $followupFee = $session->fee_followup_paisa;

        $last = $previous ?? $this->lastCompleted($patient, $doctor);
        $days = $last?->scheduled_date === null ? null : (int) $last->scheduled_date->diffInDays($session->session_date, false);
        $lastDate = $last?->scheduled_date?->toDateString();

        // Which type applies: forced by the caller (follow-up rebooking), else the window decides.
        $inWindow = $last !== null && $days !== null && $days >= 0 && $days <= $windowDays;
        $type = $forcedType ?? ($inWindow ? AppointmentType::Followup : AppointmentType::New);

        if ($override !== null) {
            return new FeeDecision($type, $override->rule, $listFee, max(0, $override->feePaisa), $override->reason ?? __('booking.fee.reason.manual'), $last?->id, $lastDate, $days);
        }

        if ($channel === BookingChannel::Telemedicine) {
            $fee = (int) ($profile->telemedicine_fee_paisa ?? $listFee);

            return new FeeDecision($type, FeeRule::Telemedicine, $listFee, max(0, $fee + $this->onlineDelta($doctor, $channel)), __('booking.fee.reason.telemedicine'), $last?->id, $lastDate, $days);
        }

        if ($type === AppointmentType::Followup) {
            if ($last !== null && $days !== null && $days >= 0 && $freeDays > 0 && $days <= $freeDays) {
                return new FeeDecision(AppointmentType::Followup, FeeRule::FollowupFree, $listFee, 0, __('booking.fee.reason.followup_free', ['date' => (string) $lastDate, 'day' => (string) $days, 'window' => (string) $freeDays]), $last->id, $lastDate, $days);
            }

            $reason = $last === null
                ? __('booking.fee.reason.followup_paid_no_visit')
                : __('booking.fee.reason.followup_paid', ['date' => (string) $lastDate, 'day' => (string) $days, 'window' => (string) $windowDays]);

            return new FeeDecision(AppointmentType::Followup, FeeRule::FollowupPaid, $listFee, max(0, $followupFee + $this->onlineDelta($doctor, $channel)), $reason, $last?->id, $lastDate, $days);
        }

        return new FeeDecision(AppointmentType::New, FeeRule::New, $listFee, max(0, $listFee + $this->onlineDelta($doctor, $channel)), __('booking.fee.reason.new'), null, null, null);
    }

    /** The patient's last completed appointment with this doctor (any branch), most recent first. */
    public function lastCompleted(Patient $patient, Doctor $doctor): ?Appointment
    {
        return Appointment::query()
            ->where('patient_id', $patient->id)
            ->where('doctor_id', $doctor->id)
            ->where('status', AppointmentStatus::Completed->value)
            ->whereNotNull('scheduled_date')
            ->orderByDesc('scheduled_date')
            ->orderByDesc('id')
            ->first();
    }

    /** Online channel adds the doctor's online booking delta (never below 0 overall — clamped by the caller). */
    private function onlineDelta(Doctor $doctor, BookingChannel $channel): int
    {
        return $channel === BookingChannel::Online ? (int) ($doctor->profile->online_booking_fee_delta_paisa ?? 0) : 0;
    }
}
