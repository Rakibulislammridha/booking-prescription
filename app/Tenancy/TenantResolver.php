<?php

declare(strict_types=1);

namespace App\Tenancy;

use App\Domain\SaaS\Enums\DomainVerificationStatus;
use App\Models\Central\Domain;
use App\Models\Central\Tenant;
use Illuminate\Contracts\Cache\Repository as Cache;

/**
 * host → ?Tenant (public.domains), Redis-cached under tenancy:host:{host} (ARCHITECTURE §4.2).
 */
final class TenantResolver
{
    public function __construct(
        private readonly Cache $cache,
        private readonly TenantContext $context,
    ) {}

    public function resolve(string $host): ?Tenant
    {
        $host = self::normalise($host);
        $central = (string) config('tenancy.central_domain');

        if ($host === '' || in_array($host, [$central, 'www.'.$central, 'super.'.$central], true)) {
            return null;                                               // central surfaces
        }

        $this->context->host = $host;
        $this->context->surfaceHint = null;

        foreach ((array) config('tenancy.service_prefixes', []) as $prefix) {
            if (str_starts_with($host, $prefix.'.')) {
                $this->context->surfaceHint = $prefix;
                $host = substr($host, strlen($prefix) + 1);
                break;
            }
        }

        $ttl = (int) config('tenancy.host_cache_ttl', 300);
        $cached = $this->cache->get(self::cacheKey($host));

        if (is_int($cached)) {
            $tenant = Tenant::query()->find($cached);

            if ($tenant !== null) {
                return $tenant;
            }

            $this->cache->forget(self::cacheKey($host));
        }

        $tenant = $this->lookup($host, $central);

        if ($tenant !== null) {
            $this->cache->put(self::cacheKey($host), $tenant->id, $ttl);
        }

        return $tenant;
    }

    public function forget(string $host): void
    {
        $this->cache->forget(self::cacheKey(self::normalise($host)));
    }

    public static function cacheKey(string $host): string
    {
        return 'tenancy:host:'.$host;
    }

    public static function normalise(string $host): string
    {
        $host = strtolower(trim($host));

        return (string) preg_replace('/:\d+$/', '', $host);
    }

    private function lookup(string $host, string $central): ?Tenant
    {
        if (str_ends_with($host, '.'.$central)) {
            $slug = substr($host, 0, -strlen('.'.$central));

            if ($slug !== '' && ! str_contains($slug, '.')) {
                return Tenant::query()->where('slug', $slug)->first();
            }
        }

        return Domain::query()
            ->where('domain', $host)
            ->where('verification_status', DomainVerificationStatus::Verified->value)
            ->first()
            ?->tenant;
    }
}
