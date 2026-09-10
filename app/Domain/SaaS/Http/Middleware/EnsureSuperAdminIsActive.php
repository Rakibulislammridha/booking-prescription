<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Http\Middleware;

use App\Domain\SaaS\Services\SuperSessionIndex;
use App\Models\Central\SuperAdmin;
use App\Tenancy\Facades\Tenancy;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * The super surface's permission model, such as it is — and deliberately so.
 *
 * `super_admins` has no roles and no Spatie tables (ARCHITECTURE §6.2: "Super Admin is central"), because the
 * platform team is a handful of people and a role matrix nobody maintains is worse than none. What IS enforced,
 * on every super request:
 *
 *   1. the `super` guard (applied to the whole route group in `bootstrap/app.php`);
 *   2. the host — super routes are registered only on `super.{central}`, so a tenant host cannot reach them;
 *   3. `is_active` — REVOKED ACCESS TAKES EFFECT IMMEDIATELY. Deactivating an operator ends their live session on
 *      their next request rather than whenever it happens to expire, which is the whole point of a kill switch
 *      (and `DeactivateSuperAdmin` destroys the sessions themselves, so a browser that never makes that next
 *      request is not a live console either);
 *   4. no tenancy — a super request that somehow arrived with a tenant search path is refused rather than served,
 *      because the console reads `public` and a stray search path would make it read a clinic's tables instead.
 *
 * It also keeps the operator's entry in `SuperSessionIndex` current (the Profile screen's device list), at most
 * once a minute so a busy screen is not a Redis write storm — the same cadence as `EnforceIdleTimeout` for staff.
 */
final class EnsureSuperAdminIsActive
{
    private const INDEX_SESSION_KEY = 'super_session_index_touched_at';

    private const INDEX_TOUCH_SECONDS = 60;

    public function __construct(private readonly SuperSessionIndex $sessions) {}

    public function handle(Request $request, Closure $next): Response
    {
        $admin = Auth::guard('super')->user();

        if (! $admin instanceof SuperAdmin || ! $admin->is_active) {
            Auth::guard('super')->logout();

            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            abort(403, __('saas.super.inactive'));
        }

        if (Tenancy::check()) {
            abort(500, 'the super console must run with no tenancy initialised');
        }

        $this->touchIndex($request, $admin);

        return $next($request);
    }

    private function touchIndex(Request $request, SuperAdmin $admin): void
    {
        if (! $request->hasSession()) {
            return;
        }

        $session = $request->session();
        $now = CarbonImmutable::now()->getTimestamp();
        $last = $session->get(self::INDEX_SESSION_KEY);

        if (is_int($last) && ($now - $last) < self::INDEX_TOUCH_SECONDS) {
            return;
        }

        $sessionId = $session->getId();

        if ($this->sessions->has($admin, $sessionId)) {
            $this->sessions->touch($admin, $sessionId);
        } else {
            $this->sessions->remember($admin, $sessionId, $request->ip(), $request->userAgent());
        }

        $session->put(self::INDEX_SESSION_KEY, $now);
    }
}
