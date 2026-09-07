<?php

declare(strict_types=1);

namespace App\Http\Controllers\Site\Booking;

use App\Domain\Patients\Enums\OtpPurpose;
use App\Domain\Patients\Exceptions\InvalidMobileNumber;
use App\Domain\Patients\Exceptions\OtpThrottled;
use App\Domain\Patients\Services\OtpService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Site\Booking\RequestBookingOtpRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;

/** POST /booking/otp {mobile} — sends the booking OTP (Patients' OtpService, purpose booking, throttle:otp). JSON. */
final class OtpController extends Controller
{
    public function __invoke(RequestBookingOtpRequest $request, OtpService $otp): JsonResponse
    {
        try {
            $otp->request((string) $request->validated('mobile'), OtpPurpose::Booking, $request->ip(), locale: (string) app()->getLocale());
        } catch (InvalidMobileNumber|OtpThrottled $e) {
            throw ValidationException::withMessages(['mobile' => $e->getMessage()]);
        }

        return response()->json(['sent' => true, 'resend_in' => $otp->resendSeconds(), 'ttl' => $otp->ttlSeconds()]);
    }
}
