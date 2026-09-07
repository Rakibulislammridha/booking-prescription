<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Http\Middleware\TrustProxies as BaseTrustProxies;
use Illuminate\Http\Request;

/**
 * Trust only the proxies named in TRUSTED_PROXIES (comma list, or `*` for every caller; default: none) and never
 * X-Forwarded-Host unless TRUSTED_PROXY_HOST_HEADER=true: under Octane/FrankenPHP there is no hop stripping client
 * headers, and the host is what selects the tenant (HostResolutionTest).
 */
final class TrustProxies extends BaseTrustProxies
{
    /** @return array<int, string>|string|null */
    protected function proxies(): array|string|null
    {
        $configured = config('app.trusted_proxies');
        $configured = is_array($configured) ? array_values(array_filter(array_map('trim', $configured))) : [];

        if ($configured === []) {
            return null;
        }

        return in_array('*', $configured, true) ? '*' : $configured;
    }

    protected function headers(): int
    {
        $headers = Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PORT | Request::HEADER_X_FORWARDED_PROTO | Request::HEADER_X_FORWARDED_PREFIX;

        if ((bool) config('app.trusted_proxy_host_header', false)) {
            $headers |= Request::HEADER_X_FORWARDED_HOST;
        }

        return $headers;
    }
}
