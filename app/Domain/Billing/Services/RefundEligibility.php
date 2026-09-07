<?php

declare(strict_types=1);

namespace App\Domain\Billing\Services;

use App\Domain\Billing\Data\RefundDecision;
use App\Domain\Billing\Enums\RefundReason;
use App\Domain\Booking\Enums\AppointmentStatus;
use App\Domain\Clinic\Services\Settings;
use App\Domain\Serials\Enums\CancelReason;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Serial;
use App\Models\Tenant\SessionInstance;

/**
 * Whether a cancellation or no-show earns the patient's money back, and how much (SERIAL_ENGINE §6, §15).
 *
 *  - Cancelled BY THE CLINIC (doctor unavailable, session cancelled, duplicate, transferred) → full refund.
 *  - Cancelled by the patient at least `serial.cancel_cutoff_minutes` before the session start → full refund.
 *  - Cancelled by the patient inside the cutoff → no automatic refund; staff may still raise one by hand
 *    (`goodwill`), which is exactly why this service decides eligibility and never moves money itself.
 *  - No-show → no automatic refund; the slot was held.
 *
 * The serial engine already computes the same boolean at cancellation time and hands it over on the event; this
 * service is the standalone answer for the desk ("would a refund be due?") and the fallback when no event is at
 * hand. Both agree because both read `serial.cancel_cutoff_minutes`.
 */
final class RefundEligibility
{
    public const CUTOFF_SETTING = 'serial.cancel_cutoff_minutes';

    public function __construct(private readonly Settings $settings) {}

    public function forCancellation(Appointment $appointment, CancelReason $reason, bool $cancelledByPatient, ?Serial $serial = null): RefundDecision
    {
        if ($this->clinicAtFault($reason)) {
            return new RefundDecision(true, RefundReason::fromCancelReason($reason), null, __('billing.refund.reason.clinic_cancelled'));
        }

        $minutesBefore = $this->minutesBeforeStart($appointment, $serial);
        $cutoff = (int) $this->settings->get(self::CUTOFF_SETTING);

        if (! $cancelledByPatient) {
            return new RefundDecision(true, RefundReason::fromCancelReason($reason), $minutesBefore, __('billing.refund.reason.staff_cancelled'));
        }

        if ($minutesBefore !== null && $minutesBefore >= $cutoff) {
            return new RefundDecision(true, RefundReason::PatientCancelled, $minutesBefore, __('billing.refund.reason.before_cutoff', ['minutes' => (string) $minutesBefore, 'cutoff' => (string) $cutoff]));
        }

        return new RefundDecision(false, RefundReason::PatientCancelled, $minutesBefore, __('billing.refund.reason.after_cutoff', ['cutoff' => (string) $cutoff]));
    }

    /** A no-show keeps the fee: the serial was held and the session capacity was consumed. */
    public function forNoShow(Appointment $appointment, string $reason): RefundDecision
    {
        // `session_closed` means the clinic never called the patient — that is the clinic's fault, not a no-show.
        $clinicAtFault = $reason === 'session_closed';

        return new RefundDecision(
            $clinicAtFault,
            $clinicAtFault ? RefundReason::ServiceNotRendered : RefundReason::Other,
            null,
            $clinicAtFault ? __('billing.refund.reason.session_closed') : __('billing.refund.reason.no_show'),
        );
    }

    private function clinicAtFault(CancelReason $reason): bool
    {
        return in_array($reason, [CancelReason::DoctorUnavailable, CancelReason::SessionCancelled, CancelReason::Duplicate, CancelReason::Transferred], true);
    }

    private function minutesBeforeStart(Appointment $appointment, ?Serial $serial): ?int
    {
        $sessionId = $serial === null ? $appointment->session_instance_id : $serial->session_instance_id;

        if ($sessionId === null) {
            return null;
        }

        $session = SessionInstance::query()->find($sessionId);

        if ($session === null) {
            return null;
        }

        return (int) floor(($session->planned_start_at->getTimestamp() - now()->getTimestamp()) / 60);
    }

    /** Nothing to refund once the consultation happened. */
    public function consultationRendered(Appointment $appointment): bool
    {
        return in_array($appointment->status, [AppointmentStatus::Completed, AppointmentStatus::InConsultation], true);
    }
}
