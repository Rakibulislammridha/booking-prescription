<?php

declare(strict_types=1);

namespace App\Http\Controllers\Site\Booking;

use App\Domain\Booking\Contracts\OnlinePaymentGateway;
use App\Domain\Booking\Services\AdvancePaymentPolicy;
use App\Domain\Clinic\Services\Settings;
use App\Domain\Patients\Services\OtpService;
use App\Domain\Scheduling\Services\SessionMaterialiser;
use App\Domain\Serials\Services\CapacityService;
use App\Http\Controllers\Controller;
use App\Models\Tenant\Branch;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\DoctorSchedule;
use App\Models\Tenant\SessionInstance;
use App\Support\Clock;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * GET /booking/kiosk?branch=…[&session=…]&signature=… (signed, 12 h) — the QR at the desk (SERIAL_ENGINE §11.2):
 * today's open sessions at that branch (or the one session the QR names), mobile + name → self-booking on the
 * online pool with source kiosk (an OTP only when `kiosk.otp_required` is on). The same Booking/Doctor page renders with `kiosk` prefilled — including the
 * advance-payment notice, true when any doctor on the board asks for payment up front (BRIEF §5.C).
 */
final class KioskController extends Controller
{
    public function __invoke(Request $request, SessionMaterialiser $materialiser, CapacityService $capacity, Settings $settings, OtpService $otp, OnlinePaymentGateway $payment, AdvancePaymentPolicy $advance): Response
    {
        $branch = Branch::query()->active()->where('public_id', (string) $request->query('branch', ''))->firstOrFail();
        $today = Clock::today();
        $named = (string) $request->query('session', '');

        foreach (DoctorSchedule::query()->active()->where('branch_id', $branch->id)->distinct()->pluck('doctor_id') as $doctorId) {
            $materialiser->ensureDay($branch->id, (int) $doctorId, $today);
        }

        $sessions = SessionInstance::query()->where('branch_id', $branch->id)->whereDate('session_date', $today->toDateString())
            ->when($named !== '', fn ($q) => $q->where('public_id', $named))
            ->open()->with('doctor.profile')->orderBy('planned_start_at')->get()
            ->filter(fn (SessionInstance $s) => $s->doctor->is_active && $s->doctor->accepts_online_booking)->values();
        $remaining = $capacity->remainingFor($sessions->pluck('id')->all());
        $first = $sessions->first();
        $doctor = $first?->doctor;

        return Inertia::render('Booking/Doctor', [
            'doctor' => $doctor === null ? null : ['public_id' => $doctor->public_id, 'slug' => $doctor->slug, 'name' => $doctor->name, 'name_bn' => $doctor->name_bn, 'degrees' => $doctor->profile?->degrees, 'degrees_bn' => $doctor->profile?->degrees_bn, 'room' => $doctor->room_label,
                'fee_paisa' => (int) ($doctor->profile->new_fee_paisa ?? 0), 'followup_fee_paisa' => (int) ($doctor->profile->followup_fee_paisa ?? 0), 'free_followup_within_days' => (int) ($doctor->profile->free_followup_within_days ?? 0)],
            'branch' => ['public_id' => $branch->public_id, 'slug' => $branch->slug, 'name' => $branch->name, 'phone' => $branch->phone],
            'from' => $today->toDateString(),
            'to' => $today->toDateString(),
            'otp_required' => (bool) $settings->get('kiosk.otp_required'),
            'otp_resend_seconds' => $otp->resendSeconds(),
            'online_payment_enabled' => $payment->enabled(),
            'advance_payment_required' => $sessions->contains(fn (SessionInstance $s) => $advance->requiredBy($s->doctor)),
            'channel' => 'kiosk',
            'kiosk' => [
                'branch' => $branch->public_id,
                'sessions' => $sessions->map(fn (SessionInstance $s) => [
                    'public_id' => $s->public_id, 'code' => $s->session_code, 'date' => $s->session_date->toDateString(), 'status' => $s->status->value,
                    'planned_start_at' => $s->planned_start_at->toIso8601String(), 'online_remaining' => $remaining[$s->id]['online'] ?? 0,
                    'doctor' => ['public_id' => $s->doctor->public_id, 'slug' => $s->doctor->slug, 'name' => $s->doctor->name, 'name_bn' => $s->doctor->name_bn, 'fee_paisa' => (int) ($s->doctor->profile->new_fee_paisa ?? 0)],
                ])->values()->all(),
            ],
        ]);
    }
}
