<?php

declare(strict_types=1);

namespace App\Http\Controllers\Site\Portal;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Patients\Enums\OtpPurpose;
use App\Domain\Patients\Exceptions\OtpAttemptsExceeded;
use App\Domain\Patients\Exceptions\OtpExpired;
use App\Domain\Patients\Exceptions\OtpInvalid;
use App\Domain\Patients\Exceptions\OtpThrottled;
use App\Domain\Patients\Services\MobileNumber;
use App\Domain\Patients\Services\OtpService;
use App\Http\Controllers\Controller;
use App\Http\Requests\Site\Portal\RequestOtpRequest;
use App\Http\Requests\Site\Portal\VerifyOtpRequest;
use App\Models\Tenant\Patient;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Patient portal login (ARCHITECTURE §6.3, guard `patient`): mobile → OTP → session. Only a mobile that already
 * belongs to a patient can log in (a person is never created without a name — SCHEMA §5.4); the household's
 * owner is the authenticated patient and `session('patient.acting_for')` selects the family member.
 */
final class OtpController extends Controller
{
    public const SESSION_MOBILE = 'portal.otp_mobile';

    public const SESSION_ACTING_FOR = 'patient.acting_for';

    public function create(Request $request): Response
    {
        return Inertia::render('Portal/Login', ['status' => $request->session()->get('status')]);
    }

    public function request(RequestOtpRequest $request, OtpService $otp): RedirectResponse
    {
        $mobile = $request->mobile();

        if (! Patient::query()->active()->where('mobile', $mobile)->exists()) {
            throw ValidationException::withMessages(['mobile' => __('portal.login.unknown_mobile')]);
        }

        try {
            $otp->request($mobile, OtpPurpose::Login, $request->ip(), locale: (string) app()->getLocale());
        } catch (OtpThrottled $e) {
            throw ValidationException::withMessages(['mobile' => $e->getMessage()]);
        }

        $request->session()->put(self::SESSION_MOBILE, $mobile);

        return redirect()->route('site.portal.verify')->with('status', __('portal.login.code_sent', ['mobile' => MobileNumber::mask($mobile)]));
    }

    public function verifyForm(Request $request, OtpService $otp): Response|RedirectResponse
    {
        $mobile = (string) $request->session()->get(self::SESSION_MOBILE, '');

        if ($mobile === '') {
            return redirect()->route('site.portal.login');
        }

        return Inertia::render('Portal/Verify', [
            'mobile' => MobileNumber::toLocal($mobile),
            'resend_after' => $otp->resendAvailableIn($mobile) ?: $otp->resendSeconds(),
            'status' => $request->session()->get('status'),
        ]);
    }

    public function verify(VerifyOtpRequest $request, OtpService $otp, AuditRecorder $audit): RedirectResponse
    {
        $mobile = $request->mobile();

        try {
            $otp->verify($mobile, $request->code(), OtpPurpose::Login);
        } catch (OtpInvalid|OtpExpired|OtpAttemptsExceeded $e) {
            throw ValidationException::withMessages(['code' => __('portal.login.'.match ($e::class) {
                OtpExpired::class => 'code_expired',
                OtpAttemptsExceeded::class => 'too_many_attempts',
                default => 'code_invalid',
            })]);
        }

        $patient = Patient::query()->active()->household($mobile)->first();

        if ($patient === null) {
            throw ValidationException::withMessages(['mobile' => __('portal.login.unknown_mobile')]);
        }

        Auth::guard('patient')->login($patient);
        $request->session()->regenerate();
        $request->session()->forget(self::SESSION_MOBILE);
        $request->session()->put(self::SESSION_ACTING_FOR, $patient->public_id);
        $audit->record(AuditAction::Login, $patient, null, null, ['guard' => 'patient']);

        return redirect()->intended(route('site.portal.home', absolute: false));
    }

    public function destroy(Request $request, AuditRecorder $audit): RedirectResponse
    {
        $patient = $request->user('patient');

        if ($patient instanceof Patient) {
            $audit->record(AuditAction::Logout, $patient, null, null, ['guard' => 'patient']);
        }

        Auth::guard('patient')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('site.portal.login');
    }
}
