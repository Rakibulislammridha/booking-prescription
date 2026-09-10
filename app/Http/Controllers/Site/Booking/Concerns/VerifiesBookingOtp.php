<?php

declare(strict_types=1);

namespace App\Http\Controllers\Site\Booking\Concerns;

use App\Domain\Clinic\Services\Settings;
use App\Domain\Patients\Enums\OtpPurpose;
use App\Domain\Patients\Exceptions\OtpAttemptsExceeded;
use App\Domain\Patients\Exceptions\OtpExpired;
use App\Domain\Patients\Exceptions\OtpInvalid;
use App\Domain\Patients\Services\OtpService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\ValidationException;

/**
 * The one OTP gate in front of every self-service booking controller (site, kiosk, telemedicine, public API).
 *
 * `kiosk.otp_required` is the switch, and it is read HERE, per request: off — the default — means the `otp` field
 * is never looked at and OtpService is never called, so no code is issued, checked or stored; on means the code
 * the patient typed must verify against the one OtpService sent, and a wrong, stale or missing code is a field
 * error on `otp`. The boolean this returns is the only way `BookingRequest::$otpVerified` is ever set to true,
 * which is what lets `BookAppointment` treat the flag as proof rather than as a client claim.
 */
trait VerifiesBookingOtp
{
    private function verifyBookingOtp(FormRequest $request, OtpService $otpService, Settings $settings): bool
    {
        if (! (bool) $settings->get('kiosk.otp_required')) {
            return false;
        }

        try {
            $otpService->verify((string) $request->validated('mobile'), (string) $request->validated('otp', ''), OtpPurpose::Booking);
        } catch (OtpInvalid|OtpExpired|OtpAttemptsExceeded $e) {
            throw ValidationException::withMessages(['otp' => $e->getMessage()]);
        }

        return true;
    }
}
