<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Enums;

/**
 * `notifications.event_key` / `notification_templates.event_key` (SCHEMA §3.6) — the closed catalogue of
 * BRIEF §5.J plus the ones the other modules raise (`otp`, `payment_receipt`, `serial_*`, `telemedicine_invite`). "Report ready" is
 * deliberately absent: BRIEF §6 removed it with the lab module.
 *
 * `variables()` is the documented placeholder catalogue a template author may use for the event; the renderer
 * rejects an unknown placeholder so a typo surfaces in the preview instead of in a patient's SMS.
 *
 * Which CHANNELS an event goes out on is not an enum concern — it is deployment policy, and lives in
 * `App\Domain\Notifications\Services\EventChannels` over `config('notifications.event_channels')`.
 */
enum NotificationEvent: string
{
    case BookingConfirmed = 'booking_confirmed';
    case ReminderDayBefore = 'reminder_day_before';
    case ReminderMorning = 'reminder_morning';
    case ThreeAhead = 'three_ahead';
    case DoctorDelayed = 'doctor_delayed';
    case DoctorCancelled = 'doctor_cancelled';
    case PrescriptionReady = 'prescription_ready';
    case FollowupDue = 'followup_due';
    case Otp = 'otp';
    case PaymentReceipt = 'payment_receipt';
    case SerialTransferred = 'serial_transferred';
    case SerialPostponed = 'serial_postponed';
    case TelemedicineInvite = 'telemedicine_invite';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }

    /** Variables always available, whatever the event. */
    public const COMMON_VARIABLES = ['clinic', 'patient_name', 'link'];

    /**
     * The documented variable catalogue for this event (COMMON_VARIABLES are added by variables()).
     *
     * @return array<int, string>
     */
    public function ownVariables(): array
    {
        return match ($this) {
            self::BookingConfirmed, self::ReminderDayBefore, self::ReminderMorning => ['serial', 'doctor', 'date', 'time', 'branch'],
            self::ThreeAhead => ['serial', 'doctor', 'ahead', 'eta', 'branch'],
            self::DoctorDelayed => ['serial', 'doctor', 'delay_minutes', 'expected_start_time', 'branch'],
            self::DoctorCancelled => ['serial', 'doctor', 'date', 'time', 'reason', 'branch'],
            self::PrescriptionReady => ['doctor', 'date'],
            self::FollowupDue => ['doctor', 'date'],
            self::Otp => ['code', 'minutes'],
            self::PaymentReceipt => ['amount', 'invoice_no', 'date'],
            self::SerialTransferred, self::SerialPostponed => ['serial', 'doctor', 'new_serial', 'new_doctor', 'date', 'time', 'branch'],
            self::TelemedicineInvite => ['serial', 'doctor', 'date', 'time'],
        };
    }

    /** @return array<int, string> every placeholder a template for this event may use */
    public function variables(): array
    {
        return [...self::COMMON_VARIABLES, ...$this->ownVariables()];
    }

    /**
     * Time-critical or contractual messages ignore quiet hours: a patient three serials away, a cancelled clinic,
     * a login code and a receipt are all worse withheld than delivered at 22:30.
     */
    public function isUrgent(): bool
    {
        return in_array($this, [self::ThreeAhead, self::DoctorDelayed, self::DoctorCancelled, self::Otp, self::PaymentReceipt, self::SerialTransferred, self::SerialPostponed, self::TelemedicineInvite], true);
    }

    /** Marketing-adjacent events honour a revoked channel consent; an OTP the patient just asked for does not. */
    public function honoursConsent(): bool
    {
        return $this !== self::Otp;
    }

    /** Reminders are produced by `notifications:send-reminders`, not by a domain event. */
    public function isScheduled(): bool
    {
        return in_array($this, [self::ReminderDayBefore, self::ReminderMorning, self::FollowupDue], true);
    }
}
