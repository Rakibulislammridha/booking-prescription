<?php

declare(strict_types=1);

namespace App\Tenancy\Http\Middleware;

use App\Models\Central\Tenant;
use App\Tenancy\Facades\Tenancy;
use App\Tenancy\TenantResolver;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Session\SessionManager;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * Global: resolves the request host to a tenant and initialises tenancy before session/auth run. Never aborts.
 * Also names the session cookie per tenant (`bp_{slug}_session`) before StartSession reads it, so a cookie minted on
 * one tenant host is never even read on another (SessionReplayAcrossTenantsTest); EnsureSessionBelongsToTenant is
 * the second layer.
 */
final class ResolveTenant
{
    public function __construct(
        private readonly TenantResolver $resolver,
        private readonly SessionManager $sessions,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        URL::forceRootUrl($request->getSchemeAndHttpHost());

        $tenant = $this->resolver->resolve($request->getHost());

        // A request always starts from its host: a tenancy left over from a previous operation in the same
        // process (tests, sync jobs) is ended — central requests run on 'public', never on a stale tenant.
        if (Tenancy::check() && Tenancy::id() !== $tenant?->id) {
            Log::warning('tenancy.switch', ['from' => Tenancy::id(), 'to' => $tenant?->id, 'host' => $request->getHost()]);
            Tenancy::end();
        }

        if ($tenant !== null) {
            Tenancy::initialize($tenant);
            $this->allowStatefulHost($request->getHost());
        }

        $this->useSessionCookieFor($tenant);

        return $next($request);
    }

    /** The cookie name every tenant host uses; central/super hosts keep the configured base name. */
    public static function sessionCookieName(?Tenant $tenant): string
    {
        $base = (string) config('tenancy.session_cookie_base', config('session.cookie'));

        if ($tenant === null) {
            return $base;
        }

        return sprintf('%s_%s_session', (string) config('tenancy.session_cookie_prefix', 'bp'), $tenant->slug);
    }

    private function useSessionCookieFor(?Tenant $tenant): void
    {
        $name = self::sessionCookieName($tenant);

        config(['session.cookie' => $name]);

        if (config('session.driver') !== null) {
            $this->sessions->driver()->setName($name);                 // the Store outlives the request under Octane
        }
    }

    /** Same-origin Sanctum cookies for every tenant host (the central wildcard is set by AuthServiceProvider). */
    private function allowStatefulHost(string $host): void
    {
        $stateful = (array) config('sanctum.stateful', []);

        if (! in_array($host, $stateful, true)) {
            $stateful[] = $host;
            config(['sanctum.stateful' => $stateful]);
        }
    }
}
