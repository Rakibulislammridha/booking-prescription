<?php

declare(strict_types=1);

namespace App\Http\Controllers\Site\Booking;

use App\Domain\Booking\Actions\BookAppointment;
use App\Domain\Booking\Contracts\OnlinePaymentGateway;
use App\Domain\Clinic\Services\Settings;
use App\Domain\Patients\Enums\OtpPurpose;
use App\Domain\Patients\Exceptions\OtpAttemptsExceeded;
use App\Domain\Patients\Exceptions\OtpExpired;
use App\Domain\Patients\Exceptions\OtpInvalid;
use App\Domain\Patients\Services\OtpService;
use App\Domain\Shared\Actor;
use App\Http\Controllers\Controller;
use App\Http\Requests\Site\Booking\StoreBookingRequest;
use App\Http\Resources\Booking\AppointmentResource;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Branch;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\DoctorSchedule;
use App\Models\Tenant\Specialty;
use App\Support\Clock;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The public booking site (BRIEF §5.C channel 1, Tailwind, no MUI): doctor search by specialty / name / day →
 * calendar with online serials remaining (api.scheduling.availability) → mobile + OTP → confirmation with the
 * serial code and the public queue link. Payment: "pay at counter" unless the OnlinePaymentGateway says otherwise.
 */
final class BookingController extends Controller
{
    public function index(Request $request): Response
    {
        $specialtySlug = (string) $request->query('specialty', '');
        $q = trim((string) $request->query('q', ''));
        $weekday = $request->query('day');
        $weekday = is_numeric($weekday) ? (int) $weekday : null;

        $doctors = Doctor::query()->active()->where('accepts_online_booking', true)
            ->with(['specialties', 'profile'])
            ->when($specialtySlug !== '', fn ($query) => $query->whereHas('specialties', fn ($s) => $s->where('slug', $specialtySlug)))
            ->when($q !== '', fn ($query) => $query->where(fn ($w) => $w->where('name', 'ilike', "%{$q}%")->orWhere('name_bn', 'ilike', "%{$q}%")))
            ->when($weekday !== null, fn ($query) => $query->whereIn('id', DoctorSchedule::query()->active()->where('weekday', $weekday)->select('doctor_id')))
            ->orderBy('sort_order')->orderBy('name')
            ->get();

        $days = DoctorSchedule::query()->active()->whereIn('doctor_id', $doctors->pluck('id')->all())->get(['doctor_id', 'weekday'])->groupBy('doctor_id');

        return Inertia::render('Booking/Index', [
            'filters' => ['specialty' => $specialtySlug, 'q' => $q, 'day' => $weekday],
            'specialties' => Specialty::query()->where('is_active', true)->orderBy('sort_order')->orderBy('name')->get(['slug', 'name', 'name_bn'])->map(fn (Specialty $s) => ['slug' => $s->slug, 'name' => $s->name, 'name_bn' => $s->name_bn])->values(),
            'doctors' => $doctors->map(fn (Doctor $d) => [
                'public_id' => $d->public_id, 'slug' => $d->slug, 'name' => $d->name, 'name_bn' => $d->name_bn,
                'degrees' => $d->profile?->degrees, 'degrees_bn' => $d->profile?->degrees_bn, 'designation' => $d->profile?->designation,
                'fee_paisa' => (int) ($d->profile->new_fee_paisa ?? 0),
                'specialties' => $d->specialties->map(fn (Specialty $s) => ['slug' => $s->slug, 'name' => $s->name, 'name_bn' => $s->name_bn])->values()->all(),
                'weekdays' => $days->get($d->id, collect())->pluck('weekday')->unique()->sort()->values()->all(),
            ])->values(),
        ]);
    }

    public function doctor(Request $request, string $doctor, Settings $settings, OnlinePaymentGateway $payment, OtpService $otp): Response
    {
        $model = Doctor::query()->active()->where('accepts_online_booking', true)->where('slug', $doctor)->with('profile')->firstOrFail();
        $branch = Branch::query()->active()->orderByDesc('is_main')->orderBy('id')->firstOrFail();

        return Inertia::render('Booking/Doctor', [
            'doctor' => ['public_id' => $model->public_id, 'slug' => $model->slug, 'name' => $model->name, 'name_bn' => $model->name_bn, 'degrees' => $model->profile?->degrees, 'degrees_bn' => $model->profile?->degrees_bn, 'room' => $model->room_label,
                'fee_paisa' => (int) ($model->profile->new_fee_paisa ?? 0), 'followup_fee_paisa' => (int) ($model->profile->followup_fee_paisa ?? 0), 'free_followup_within_days' => (int) ($model->profile->free_followup_within_days ?? 0)],
            'branch' => ['public_id' => $branch->public_id, 'slug' => $branch->slug, 'name' => $branch->name, 'phone' => $branch->phone],
            'from' => Clock::today()->toDateString(),
            'to' => Clock::today()->addDays(13)->toDateString(),
            'otp_required' => (bool) $settings->get('kiosk.otp_required'),
            'otp_resend_seconds' => $otp->resendSeconds(),
            'online_payment_enabled' => $payment->enabled(),
            'channel' => 'online',
            'kiosk' => null,
        ]);
    }

    public function store(StoreBookingRequest $request, BookAppointment $book, OtpService $otpService, Settings $settings, OnlinePaymentGateway $payment): RedirectResponse
    {
        $verified = $this->verifyOtp($request, $otpService, (bool) $settings->get('kiosk.otp_required'));
        $result = $book->handle($request->toData($verified), new Actor(ip: $request->ip(), source: 'web'));
        $checkout = $payment->checkoutUrl($result->appointment);

        return redirect()->to($checkout ?? route('site.booking.confirmed', ['appointment' => $result->appointment->public_id]));
    }

    public function confirmed(Request $request, Appointment $appointment, OnlinePaymentGateway $payment): Response
    {
        $appointment->load(['patient', 'doctor', 'sessionInstance', 'serial', 'branch']);

        return Inertia::render('Booking/Confirmed', [
            'appointment' => (new AppointmentResource($appointment))->toArray($request),
            'queue_url' => '/q/'.$appointment->doctor->slug.'/today?s='.($appointment->serial->public_id ?? ''),
            'branch' => ['name' => $appointment->branch->name, 'phone' => $appointment->branch->phone, 'address' => $appointment->branch->address],
            'pay_at_counter' => ! $payment->enabled(),
        ]);
    }

    /** OTP is required for self-service booking when the tenant says so (kiosk.otp_required, default true). */
    private function verifyOtp(StoreBookingRequest $request, OtpService $otpService, bool $required): bool
    {
        $code = (string) $request->validated('otp', '');

        if (! $required && $code === '') {
            return false;
        }

        try {
            $otpService->verify((string) $request->validated('mobile'), $code, OtpPurpose::Booking);
        } catch (OtpInvalid|OtpExpired|OtpAttemptsExceeded $e) {
            throw ValidationException::withMessages(['otp' => $e->getMessage()]);
        }

        return true;
    }
}
