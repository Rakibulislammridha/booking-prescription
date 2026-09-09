<?php

declare(strict_types=1);

namespace App\Http\Controllers\Super\Auth;

use App\Domain\SaaS\Services\CentralAudit;
use App\Domain\SaaS\Services\SuperTwoFactor;
use App\Http\Controllers\Controller;
use App\Http\Controllers\Super\Auth\Concerns\CompletesSuperLogin;
use App\Http\Requests\Super\Auth\TwoFactorChallengeRequest;
use App\Models\Central\SuperAdmin;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The second half of a super login (ARCHITECTURE §6.5): the password has matched, nobody is authenticated yet.
 *
 * The pending marker is treated as a credential in its own right — it expires (`saas.two_factor.pending_ttl_seconds`),
 * it is re-checked against a live, still-active admin on every attempt, and it is destroyed the moment the
 * challenge succeeds. Failures are rate-limited per admin+IP *and* audited centrally, because a burst of
 * `two_factor_failed` rows against one operator is the earliest signal the platform has that their password leaked.
 */
final class TwoFactorChallengeController extends Controller
{
    use CompletesSuperLogin;

    public function __construct(
        private readonly SuperTwoFactor $twoFactor,
        private readonly CentralAudit $audit,
    ) {}

    public function create(Request $request): Response|RedirectResponse
    {
        $admin = $this->pendingAdmin($request);

        if (! $admin instanceof SuperAdmin) {
            return redirect()->route('super.login');
        }

        return Inertia::render('Super/Auth/TwoFactorChallenge', [
            'recovery_available' => $this->twoFactor->recoveryCodesRemaining($admin) > 0,
        ]);
    }

    public function store(TwoFactorChallengeRequest $request): RedirectResponse
    {
        $admin = $this->pendingAdmin($request);

        if (! $admin instanceof SuperAdmin) {
            return redirect()->route('super.login')->with('flash.error', __('auth.two_factor.expired'));
        }

        $key = 'super-2fa:'.$admin->id.'|'.$request->ip();
        $attempts = (int) config('saas.two_factor.challenge_attempts', 5);
        $decay = (int) config('saas.two_factor.challenge_decay_seconds', 900);

        if (RateLimiter::tooManyAttempts($key, $attempts)) {
            throw ValidationException::withMessages(['code' => __('auth.throttle', ['seconds' => RateLimiter::availableIn($key)])]);
        }

        $recovery = $request->usesRecoveryCode();

        $passed = $recovery
            ? $this->twoFactor->verifyRecoveryCode($admin, $request->recoveryCode())
            : $this->twoFactor->verifyCode($admin, $request->code());

        if (! $passed) {
            RateLimiter::hit($key, $decay);
            $this->twoFactor->recordFailedChallenge($admin, $recovery ? 'recovery_code' : 'totp');

            throw ValidationException::withMessages([
                $recovery ? 'recovery_code' : 'code' => __('auth.two_factor.invalid'),
            ]);
        }

        RateLimiter::clear($key);

        Auth::guard('super')->login($admin);        // no remember-me on the super guard (B4)
        $request->session()->regenerate();          // the session id that carried the pending marker never becomes a console session

        return $this->completeSuperLogin($request, $admin, $this->audit);
    }

    /** @return array<string, mixed>|null */
    private function pending(Request $request): ?array
    {
        if (! $request->hasSession()) {
            return null;
        }

        $pending = $request->session()->get(SuperTwoFactor::SESSION_PENDING);

        if (! is_array($pending) || ! isset($pending['id'], $pending['at'])) {
            return null;
        }

        $ttl = (int) config('saas.two_factor.pending_ttl_seconds', 300);

        if (CarbonImmutable::now()->getTimestamp() - (int) $pending['at'] > $ttl) {
            $request->session()->forget(SuperTwoFactor::SESSION_PENDING);

            return null;
        }

        return $pending;
    }

    private function pendingAdmin(Request $request): ?SuperAdmin
    {
        $pending = $this->pending($request);

        if ($pending === null) {
            return null;
        }

        $admin = SuperAdmin::query()->whereKey((int) $pending['id'])->where('is_active', true)->first();

        // Deactivated, deleted, or their second factor was removed while they were reaching for the phone:
        // the half-finished login is void rather than silently upgraded into a password-only login.
        return $admin instanceof SuperAdmin && $this->twoFactor->enabled($admin) ? $admin : null;
    }
}
