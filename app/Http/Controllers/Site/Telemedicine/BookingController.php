<?php

declare(strict_types=1);

namespace App\Http\Controllers\Site\Telemedicine;

use App\Domain\Booking\Actions\BookAppointment;
use App\Domain\Clinic\Services\Settings;
use App\Domain\Patients\Services\OtpService;
use App\Domain\Shared\Actor;
use App\Domain\Telemedicine\Exceptions\DoctorNotTelemedicine;
use App\Domain\Telemedicine\Services\JoinLink;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Site\Booking\Concerns\VerifiesBookingOtp;
use App\Http\Requests\Site\Telemedicine\StoreTelemedicineBookingRequest;
use App\Http\Resources\Booking\AppointmentResource;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\DoctorSchedule;
use App\Models\Tenant\SessionInstance;
use App\Models\Tenant\TelemedicineRoom;
use App\Support\Clock;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Booking a video consultation. The flow is the ORDINARY one — `BookAppointment` with
 * `BookingChannel::Telemedicine` — so the patient is matched or created the same way, a serial is allocated
 * atomically from the same online pool, `FeeResolver` snapshots the doctor's `telemedicine_fee_paisa` under the
 * `telemedicine` fee rule, the same invoice path runs and `AppointmentBooked` fires for Billing, SaaS usage and
 * Notifications. Nothing about the money or the queue is special (BRIEF §5.I).
 *
 * It lives here rather than in the Booking module's own pages only because the channel is gated on a plan
 * add-on: this whole route file is behind `EnsureTelemedicineEnabled`, so an unentitled clinic never renders the
 * channel at all (BRIEF §5.M), and the Booking module's screens stay untouched.
 */
final class BookingController extends Controller
{
    use VerifiesBookingOtp;

    public function index(Request $request, Settings $settings, OtpService $otp): Response
    {
        $doctors = Doctor::query()->active()
            ->where('accepts_telemedicine', true)
            ->where('accepts_online_booking', true)
            ->with(['profile', 'specialties'])
            ->orderBy('sort_order')->orderBy('name')
            ->get();

        $days = DoctorSchedule::query()->active()->whereIn('doctor_id', $doctors->pluck('id')->all())->get(['doctor_id', 'weekday'])->groupBy('doctor_id');

        return Inertia::render('Telemedicine/Book', [
            'doctors' => $doctors->map(fn (Doctor $d) => [
                'public_id' => $d->public_id, 'slug' => $d->slug, 'name' => $d->name, 'name_bn' => $d->name_bn,
                'degrees' => $d->profile?->degrees, 'designation' => $d->profile?->designation,
                'fee_paisa' => (int) ($d->profile->telemedicine_fee_paisa ?? $d->profile->new_fee_paisa ?? 0),
                'weekdays' => $days->get($d->id, collect())->pluck('weekday')->unique()->sort()->values()->all(),
            ])->values(),
            'from' => Clock::today()->toDateString(),
            'to' => Clock::today()->addDays(13)->toDateString(),
            'otp_required' => (bool) $settings->get('kiosk.otp_required'),
            'otp_resend_seconds' => $otp->resendSeconds(),
        ]);
    }

    public function store(StoreTelemedicineBookingRequest $request, BookAppointment $book, OtpService $otpService, Settings $settings): RedirectResponse
    {
        $this->assertDoctorConsults((string) $request->validated('session'));
        $verified = $this->verifyBookingOtp($request, $otpService, $settings);
        $result = $book->handle($request->toData($verified), new Actor(ip: $request->ip(), source: 'web'));

        return redirect()->route('site.telemedicine.booked', ['appointment' => $result->appointment->public_id]);
    }

    public function booked(Request $request, Appointment $appointment, JoinLink $links): Response
    {
        abort_unless($appointment->is_telemedicine, 404);
        $appointment->load(['patient', 'doctor', 'sessionInstance', 'serial', 'branch']);
        $room = TelemedicineRoom::query()->where('appointment_id', $appointment->id)->first();

        return Inertia::render('Telemedicine/Booked', [
            'appointment' => (new AppointmentResource($appointment))->toArray($request),
            'join_url' => $room === null ? null : $links->relative($room),
            'room' => $room?->room_name,
            'queue_url' => '/q/'.$appointment->doctor->slug.'/today?s='.($appointment->serial->public_id ?? ''),
            'branch' => ['name' => $appointment->branch->name, 'phone' => $appointment->branch->phone],
        ]);
    }

    /** `doctors.accepts_telemedicine` — a doctor who does not consult over video is not bookable over video. */
    private function assertDoctorConsults(string $sessionPublicId): void
    {
        $doctorId = SessionInstance::query()->where('public_id', $sessionPublicId)->value('doctor_id');
        $accepts = $doctorId === null ? false : (bool) Doctor::query()->whereKey($doctorId)->value('accepts_telemedicine');

        if (! $accepts) {
            throw new DoctorNotTelemedicine;
        }
    }
}
