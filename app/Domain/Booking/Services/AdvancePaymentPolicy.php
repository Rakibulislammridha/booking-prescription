<?php

declare(strict_types=1);

namespace App\Domain\Booking\Services;

use App\Domain\Booking\Contracts\OnlinePaymentGateway;
use App\Domain\Booking\Enums\AppointmentStatus;
use App\Domain\Booking\Enums\BookingChannel;
use App\Domain\Booking\Enums\PaymentStatus;
use App\Domain\Booking\Exceptions\AdvancePaymentUnavailable;
use App\Domain\Clinic\Services\Settings;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Doctor;
use Carbon\CarbonInterface;

/**
 * The "advance" leg of BRIEF §5.C's "payment (optional / advance / full)": `doctor_profiles.advance_payment_required`
 * turns a SELF-SERVICE booking (online site + kiosk/QR — the only channels the setting's own label is about) into a
 * held serial that confirms when the money lands, instead of a serial confirmed on trust.
 *
 * Three conditions, all required: the channel is self-service, the doctor's profile asks for it, and the resolved
 * fee is above zero — a free follow-up is never held for a payment that will never be made.
 *
 * Nothing here touches the LOCKED serial engine: allocation stays exactly as SERIAL_ENGINE §4 describes it, and an
 * expired hold is released through the ordinary CancelAppointment → CancelSerial path (§6).
 */
final class AdvancePaymentPolicy
{
    /** SCHEMA Appendix B — how long an unpaid hold keeps its number before `booking:expire-holds` releases it. */
    public const HOLD_SETTING = 'booking.advance_payment_hold_minutes';

    public function __construct(
        private readonly OnlinePaymentGateway $gateway,
        private readonly Settings $settings,
    ) {}

    /** The doctor's own switch, for the booking page's notice (the fee is not known until the patient picks). */
    public function requiredBy(?Doctor $doctor): bool
    {
        return (bool) ($doctor->profile->advance_payment_required ?? false);
    }

    /** Would this exact booking be held? Channel + doctor + the fee FeeResolver actually decided. */
    public function applies(BookingChannel $channel, Doctor $doctor, int $feePaisa): bool
    {
        return $channel->isSelfService() && $feePaisa > 0 && $this->requiredBy($doctor);
    }

    /**
     * Called by BookAppointment before the serial is allocated.
     *
     * @return bool whether the appointment must be written as a held (pending, unconfirmed) booking
     *
     * @throws AdvancePaymentUnavailable when payment is required and this tenant cannot take it online
     */
    public function guard(BookingChannel $channel, Doctor $doctor, int $feePaisa): bool
    {
        if (! $this->applies($channel, $doctor, $feePaisa)) {
            return false;
        }

        if (! $this->gateway->enabled()) {
            throw new AdvancePaymentUnavailable;
        }

        return true;
    }

    /** Minutes an unpaid hold survives (settings key, default 30). */
    public function holdMinutes(): int
    {
        return max(1, (int) $this->settings->get(self::HOLD_SETTING));
    }

    /**
     * Still a number held for money that has not arrived: pending, and not a paisa settled. A part-paid hold is
     * pending but is NOT one of these (the sweep leaves it, the board shows it without a countdown).
     */
    public function isUnpaidHold(Appointment $appointment): bool
    {
        return $appointment->status === AppointmentStatus::Pending && $appointment->payment_status === PaymentStatus::Unpaid;
    }

    /**
     * The sweep's whole predicate on ONE row — the only thing that may decide a release. `booking:expire-holds`
     * selects candidates with the same three conditions, but a candidate list is a snapshot: the decision is
     * taken by re-evaluating this under the appointment's row lock (ReleaseExpiredHold), so money that landed
     * after the snapshot keeps the booking.
     */
    public function isExpiredHold(Appointment $appointment, CarbonInterface $cutoff): bool
    {
        return $this->isUnpaidHold($appointment) && $appointment->created_at !== null && $appointment->created_at->lessThan($cutoff);
    }

    public function onlinePaymentEnabled(): bool
    {
        return $this->gateway->enabled();
    }
}
