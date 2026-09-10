<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Booking;

use App\Domain\Booking\Actions\BookAppointment;
use App\Domain\Clinic\Services\Settings;
use App\Domain\Patients\Services\OtpService;
use App\Domain\Queue\Support\QueueLinks;
use App\Domain\Shared\Actor;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Site\Booking\Concerns\VerifiesBookingOtp;
use App\Http\Requests\Api\Booking\StorePublicBookingRequest;
use App\Http\Resources\Booking\AppointmentResource;
use App\Models\Tenant\Branch;
use Illuminate\Http\JsonResponse;

/**
 * POST /api/public/bookings (SERIAL_ENGINE §16, no auth, throttle:booking) → 201 {serial, appointment}. The OTP
 * gate is the site's (VerifiesBookingOtp): a code is checked only when `kiosk.otp_required` is on.
 */
final class PublicBookingController extends Controller
{
    use VerifiesBookingOtp;

    public function __invoke(StorePublicBookingRequest $request, BookAppointment $book, OtpService $otpService, Settings $settings): JsonResponse
    {
        $branchSlug = (string) $request->validated('branch', '');
        $branchId = $branchSlug === '' ? null : Branch::query()->active()->where('slug', $branchSlug)->value('id');
        $verified = $this->verifyBookingOtp($request, $otpService, $settings);

        $result = $book->handle($request->toData($verified, is_numeric($branchId) ? (int) $branchId : null), new Actor(ip: $request->ip(), source: 'api'));
        $appointment = $result->appointment->load(['patient', 'doctor', 'sessionInstance', 'serial']);

        return response()->json([
            'serial' => [
                'public_id' => $result->serial->public_id,
                'display_code' => $result->serial->display_code,
                'number' => $result->serial->number,
                'queue_url' => QueueLinks::forSerial($appointment->doctor->slug, $result->serial->public_id),
            ],
            'appointment' => (new AppointmentResource($appointment))->toArray($request),
            'replayed' => $result->replayed,
        ], $result->replayed ? 200 : 201);
    }
}
