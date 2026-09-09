<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Http\Middleware;

use App\Domain\SaaS\Services\SuperTwoFactor;
use App\Models\Central\SuperAdmin;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * The second factor, enforced on every super route (ARCHITECTURE §6.5). Sits on the whole `super.` group in
 * `bootstrap/app.php`; the login pair, the challenge and the enrolment screen opt out with `withoutMiddleware`.
 *
 * What it enforces is the platform policy `security.super_two_factor` (SuperTwoFactorPolicy), read from
 * `SuperTwoFactor` on EVERY request — never at boot — so the console toggle takes effect on the next request:
 *
 *  1. **A session that has not passed the challenge is not a session.** For an operator the policy challenges
 *     (enrolled, and the policy is `required` or `optional`) the login controller does not log them in at all —
 *     it parks a half-finished login in `SESSION_PENDING` and the challenge finishes it, stamping
 *     `SESSION_PASSED_AT` — so ordinarily an unchallenged session is simply a guest. This check is the belt to
 *     that's braces: any session on the `super` guard for such an operator WITHOUT the stamp is logged out here
 *     rather than being allowed to mint an impersonation token. It is also what makes a policy change bite
 *     immediately: an enrolled operator who signed in with the password alone while the policy was `disabled`
 *     carries no stamp, and the first request after the policy is switched back sends them to sign in properly.
 *  2. **Forced enrolment.** Under `required`, an operator who has not enrolled is sent to the enrolment screen
 *     and can reach nothing else. Not a dismissible banner: the account that can read every clinic's records
 *     does not get to postpone this. Their other doors are `super.logout` and the Platform settings screen —
 *     the policy is set from the same console, an un-enrolled password-holder could pass this wall by enrolling
 *     a phone of their own anyway, and a wall with no switch behind it is how a platform locks itself out of
 *     its own console.
 *  3. Under `disabled` nothing is challenged and nothing is forced, enrolled or not.
 *
 * This middleware runs on the `security/two-factor/*` management routes too (they used to opt out of it). That
 * is what closes B4: an ENABLED operator whose session never passed the challenge — e.g. one re-authenticated by
 * some other path — hits condition (1) and is logged out BEFORE it can reach `recoveryCodes`, which minted fresh
 * codes with no code and no password. The enrolment-in-progress flow is preserved by letting a NOT-YET-enabled
 * operator reach the `super.two-factor.*` management routes (condition 2), which is the one place forced enrolment
 * is allowed to land.
 */
final class EnsureSuperTwoFactor
{
    /** Route patterns an operator held on forced enrolment may still reach. */
    private const OPEN_DURING_ENROLMENT = ['super.two-factor.*', 'super.settings.*'];

    public function __construct(private readonly SuperTwoFactor $twoFactor) {}

    public function handle(Request $request, Closure $next): Response
    {
        $admin = Auth::guard('super')->user();

        if (! $admin instanceof SuperAdmin) {
            return $next($request);                     // `auth:super` owns the guest case; this middleware runs after it
        }

        if ($this->twoFactor->challenges($admin)) {
            if ($request->hasSession() && $request->session()->has(SuperTwoFactor::SESSION_PASSED_AT)) {
                return $next($request);
            }

            Auth::guard('super')->logout();

            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            return $this->refuse($request, route('super.login'), __('auth.two_factor.challenge_required'));
        }

        if ($this->twoFactor->mustEnrol($admin)) {
            // Not enabled yet, and enrolment is mandatory: the enrolment/management routes are where the operator
            // is being sent, so let those (and the policy switch) through and hold everything else on the
            // enrolment screen.
            if ($request->routeIs(...self::OPEN_DURING_ENROLMENT)) {
                return $next($request);
            }

            return $this->refuse($request, route('super.two-factor.show'), __('auth.two_factor.enrolment_required'));
        }

        return $next($request);
    }

    private function refuse(Request $request, string $url, string $message): Response
    {
        return $request->expectsJson()
            ? response()->json(['message' => $message], 403)
            : redirect()->guest($url);
    }
}
