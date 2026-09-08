<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Http\Middleware;

use App\Models\Central\SuperAdmin;
use App\Tenancy\Facades\Tenancy;
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
 *      their next request rather than whenever it happens to expire, which is the whole point of a kill switch;
 *   4. no tenancy — a super request that somehow arrived with a tenant search path is refused rather than served,
 *      because the console reads `public` and a stray search path would make it read a clinic's tables instead.
 */
final class EnsureSuperAdminIsActive
{
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

        return $next($request);
    }
}
