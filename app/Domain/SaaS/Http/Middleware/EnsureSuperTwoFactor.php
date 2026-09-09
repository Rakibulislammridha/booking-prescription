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
 * Two separate conditions, and both matter:
 *
 *  1. **A session that has not passed the challenge is not a session.** The login controller does not log an
 *     enrolled admin in at all — it parks a half-finished login in `SESSION_PENDING` and the challenge finishes
 *     it — so ordinarily an unchallenged session is simply a guest. This check is the belt to that's braces: if
 *     any other path ever puts an enrolled operator on the `super` guard without stamping `SESSION_PASSED_AT`,
 *     that session is logged out here rather than being allowed to mint an impersonation token.
 *  2. **Forced enrolment.** With `saas.two_factor.required` on, an operator who has not enrolled is sent to the
 *     enrolment screen and can reach nothing else. Not a dismissible banner: the account that can read every
 *     clinic's records does not get to postpone this. Their only other option is `super.logout`.
 *
 * This middleware now runs on the `security/two-factor/*` management routes too (they used to opt out of it). That
 * is what closes B4: an ENABLED operator whose session never passed the challenge — e.g. one re-authenticated by
 * some other path — hits condition (1) and is logged out BEFORE it can reach `recoveryCodes`, which minted fresh
 * codes with no code and no password. The enrolment-in-progress flow is preserved by letting a NOT-YET-enabled
 * operator reach the `super.two-factor.*` management routes (condition 2), which is the one place forced enrolment
 * is allowed to land.
 */
final class EnsureSuperTwoFactor
{
    public function __construct(private readonly SuperTwoFactor $twoFactor) {}

    public function handle(Request $request, Closure $next): Response
    {
        $admin = Auth::guard('super')->user();

        if (! $admin instanceof SuperAdmin) {
            return $next($request);                     // `auth:super` owns the guest case; this middleware runs after it
        }

        if ($this->twoFactor->enabled($admin)) {
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

        if ($this->twoFactor->required()) {
            // Not enabled yet, and enrolment is mandatory: the enrolment/management routes are where the operator
            // is being sent, so let those through and hold everything else on the enrolment screen.
            if ($request->routeIs('super.two-factor.*')) {
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
