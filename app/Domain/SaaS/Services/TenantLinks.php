<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Services;

use App\Models\Central\Tenant;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/**
 * Absolute URLs on a CLINIC's host, built from the super console.
 *
 * Ziggy and `route()` cannot do this: `ResolveTenant` forces the URL root to the host of the current request, so
 * on `super.{central}` every generated URL points at the console — including the password-reset link Laravel's
 * `ResetPassword` notification builds through `panel.password.reset`. `onTenantHost()` swaps the root for the
 * duration of one closure and restores it, which is how a set-password e-mail sent from the console carries a
 * link the clinic's own host answers. The port is kept: dev and CI serve the platform on :8000 / :8090.
 */
final class TenantLinks
{
    public function root(Tenant $tenant): string
    {
        $request = app()->bound('request') ? app('request') : null;

        if ($request instanceof Request && $request->getHost() !== '') {
            $scheme = $request->getScheme();
            $port = $request->getPort();
        } else {
            $scheme = parse_url((string) config('app.url'), PHP_URL_SCHEME) ?: 'https';
            $port = parse_url((string) config('app.url'), PHP_URL_PORT) ?: ($scheme === 'https' ? 443 : 80);
        }

        $default = ($scheme === 'https' && (int) $port === 443) || ($scheme === 'http' && (int) $port === 80);

        return $scheme.'://'.$tenant->primaryHost().($default ? '' : ':'.$port);
    }

    public function panel(Tenant $tenant, string $path = '/panel'): string
    {
        return $this->root($tenant).'/'.ltrim($path, '/');
    }

    /**
     * Run `$callback` with `route()` generating URLs on the clinic's host, then put the console's root back.
     *
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public function onTenantHost(Tenant $tenant, Closure $callback): mixed
    {
        $request = app()->bound('request') ? app('request') : null;
        $previous = $request instanceof Request && $request->getHost() !== '' ? $request->getSchemeAndHttpHost() : null;

        URL::forceRootUrl($this->root($tenant));

        try {
            return $callback();
        } finally {
            URL::forceRootUrl($previous);
        }
    }
}
