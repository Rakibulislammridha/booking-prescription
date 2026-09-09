<?php

declare(strict_types=1);

namespace App\Http\Controllers\Super\Auth;

use App\Domain\SaaS\Services\SuperTwoFactor;
use App\Http\Controllers\Controller;
use App\Http\Requests\Super\Auth\ConfirmTwoFactorRequest;
use App\Http\Requests\Super\Auth\DisableTwoFactorRequest;
use App\Models\Central\SuperAdmin;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Enrolment and management of the operator's own second factor (ARCHITECTURE §6.5), on `super.{central}`.
 *
 * This is the one part of the console that `EnsureSuperTwoFactor` lets an un-enrolled operator reach — it has to
 * be, or forced enrolment would be a redirect loop. Everything here therefore acts on `$request->user('super')`
 * and never on an id from the request: an operator can only enrol, rotate or disable THEIR OWN factor, and there
 * is deliberately no "reset someone's 2FA" button in the product (that is a `php artisan` conversation with a
 * second human, not a click).
 */
final class TwoFactorController extends Controller
{
    public function __construct(private readonly SuperTwoFactor $twoFactor) {}

    public function show(Request $request): Response
    {
        $admin = $this->admin($request);
        $pending = $this->twoFactor->pendingSecret($admin);

        return Inertia::render('Super/Auth/TwoFactor', [
            'enabled' => $this->twoFactor->enabled($admin),
            'required' => $this->twoFactor->required(),
            'confirmed_at' => $this->twoFactor->enabled($admin) ? $admin->two_factor_confirmed_at?->toIso8601String() : null,
            'recovery_remaining' => $this->twoFactor->recoveryCodesRemaining($admin),
            // Present only while an enrolment is in progress; the secret leaves the server exactly once, to the
            // operator who is enrolling, over the session that is already trusted with the console.
            'enrolment' => $pending === null ? null : $this->twoFactor->enrolmentPayload($admin, $pending),
            // Flashed once by confirm()/recoveryCodes(); a refresh does not show them again.
            'recovery_codes' => $request->session()->get(SuperTwoFactor::SESSION_RECOVERY_CODES),
        ]);
    }

    /** Start (or restart) an enrolment: a fresh secret in the un-confirmed state. */
    public function store(Request $request): RedirectResponse
    {
        $admin = $this->admin($request);

        if ($this->twoFactor->enabled($admin)) {
            return back()->withErrors(['domain' => __('auth.two_factor.already_enabled')]);
        }

        $this->twoFactor->beginEnrolment($admin);

        return redirect()->route('super.two-factor.show');
    }

    /** Confirm before enable: only a code that verifies against the pending secret turns the factor on. */
    public function confirm(ConfirmTwoFactorRequest $request): RedirectResponse
    {
        $admin = $this->admin($request);
        $codes = $this->twoFactor->confirm($admin, $request->code());

        if ($codes === null) {
            return back()->withErrors(['code' => __('auth.two_factor.invalid')]);
        }

        // The operator has just proved possession, so this session has passed the challenge — sending them back
        // to the login screen for a code they demonstrated ten seconds ago would be theatre, not security.
        $request->session()->put(SuperTwoFactor::SESSION_PASSED_AT, CarbonImmutable::now()->getTimestamp());

        return redirect()->route('super.two-factor.show')
            ->with(SuperTwoFactor::SESSION_RECOVERY_CODES, $codes)
            ->with('flash.success', __('auth.two_factor.enabled'));
    }

    public function recoveryCodes(Request $request): RedirectResponse
    {
        $admin = $this->admin($request);

        if (! $this->twoFactor->enabled($admin)) {
            return back()->withErrors(['domain' => __('auth.two_factor.not_enabled')]);
        }

        return redirect()->route('super.two-factor.show')
            ->with(SuperTwoFactor::SESSION_RECOVERY_CODES, $this->twoFactor->regenerateRecoveryCodes($admin))
            ->with('flash.success', __('auth.two_factor.recovery_regenerated'));
    }

    public function destroy(DisableTwoFactorRequest $request): RedirectResponse
    {
        $this->twoFactor->disable($this->admin($request));

        return redirect()->route('super.two-factor.show')->with('flash.warning', __('auth.two_factor.disabled'));
    }

    private function admin(Request $request): SuperAdmin
    {
        $admin = $request->user('super');

        abort_unless($admin instanceof SuperAdmin, 403);

        return $admin;
    }
}
