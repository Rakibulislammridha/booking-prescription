<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Tenant\User;
use App\Tenancy\Facades\Tenancy;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Re-checks, on every authenticated panel request, that the staff account is STILL allowed to be here (B1). The
 * `web` guard authenticates from the session id (and, failing that, a recaller cookie) without ever re-reading
 * `is_active` — so an account deactivated mid-session, or one whose only credential is a remember cookie, would
 * otherwise keep working until the session lifetime ran out. State is checked once at login and never again; this
 * is where it is checked again.
 *
 * Deactivation already destroys the account's sessions and rotates its remember_token (StaffSessionIndex), which
 * closes the recaller path at its root; this middleware is the belt to that's braces and the thing that catches a
 * deactivation that happened through any other path. It sits on the `panel` group, after `auth:web` has resolved
 * (or rejected) the user.
 *
 * The tenant check is defence in depth: the schema-scoped provider and per-tenant session binding already make a
 * `web` user always belong to the current tenant, but a session that ever carried a foreign user is logged out
 * here rather than served.
 */
final class EnsureStaffIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user('web');

        if ($user instanceof User && (! $user->is_active || $user->tenant_id !== Tenancy::id())) {
            Auth::guard('web')->logout();

            if ($request->hasSession()) {
                $request->session()->invalidate();
                $request->session()->regenerateToken();
            }

            $message = __('auth.account_inactive');

            return $request->expectsJson()
                ? response()->json(['message' => $message], 401)
                : redirect()->route('panel.login')->with('flash.error', $message);
        }

        return $next($request);
    }
}
