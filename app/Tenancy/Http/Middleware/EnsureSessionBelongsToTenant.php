<?php

declare(strict_types=1);

namespace App\Tenancy\Http\Middleware;

use App\Tenancy\Facades\Tenancy;
use Closure;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

/**
 * Runs right after StartSession (web group, and inside Sanctum's stateful api pipeline): a session is bound to the
 * tenant it was created on (`tenant_id`, also written by BindSessionToTenant at login). A session presented on
 * another tenant's host — or a tenant session on a central surface, or a super session on a tenant host — is
 * invalidated before any guard can resolve `login_web_*` by a per-schema id (SessionReplayAcrossTenantsTest).
 */
final class EnsureSessionBelongsToTenant
{
    public const SESSION_KEY = 'tenant_id';

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->hasSession()) {
            $this->enforce($request->session());
        }

        return $next($request);
    }

    private function enforce(Session $session): void
    {
        $expected = Tenancy::id();
        $bound = $session->get(self::SESSION_KEY);
        $bound = $bound === null ? null : (int) $bound;

        if ($this->isForeign($session, $bound, $expected)) {
            Log::warning('session.tenant_mismatch', ['session_tenant_id' => $bound, 'request_tenant_id' => $expected]);

            $session->invalidate();
            $session->regenerateToken();
        }

        if ($expected === null) {
            $session->forget(self::SESSION_KEY);                       // super/central surfaces: the session carries no tenant
        } else {
            $session->put(self::SESSION_KEY, $expected);
        }
    }

    private function isForeign(Session $session, ?int $bound, ?int $expected): bool
    {
        if ($bound !== null) {
            return $bound !== $expected;
        }

        // Unbound session (pre-deploy, or minted on the other kind of surface): judge it by the guard markers it carries.
        $markers = array_filter(array_keys($session->all()), fn (string $key) => str_starts_with($key, 'login_'));

        foreach ($markers as $marker) {
            $isCentralGuard = str_starts_with($marker, 'login_super_');

            if ($expected === null ? ! $isCentralGuard : $isCentralGuard) {
                return true;
            }
        }

        return false;
    }
}
