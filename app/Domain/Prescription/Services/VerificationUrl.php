<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Services;

use App\Models\Central\Domain;
use App\Tenancy\Facades\Tenancy;

/** https://{tenant primary domain}/rx/{code} (PRESCRIPTION.md §7.4); falls back to {slug}.{central_domain}. */
final class VerificationUrl
{
    public static function for(string $code): string
    {
        $tenant = Tenancy::current();
        $host = null;

        if ($tenant !== null) {
            $primary = Domain::query()->where('tenant_id', $tenant->id)->where('is_primary', true)->value('domain');
            $host = is_string($primary) && $primary !== '' ? $primary : $tenant->slug.'.'.config('tenancy.central_domain');
        }

        $scheme = app()->environment('production') ? 'https' : (str_starts_with((string) config('app.url'), 'https') ? 'https' : 'http');

        return "{$scheme}://".($host ?? parse_url((string) config('app.url'), PHP_URL_HOST) ?? 'localhost')."/rx/{$code}";
    }

    public static function drugInfo(?string $slug): ?string
    {
        if ($slug === null || $slug === '') {
            return null;
        }

        return preg_replace('~/rx/.*$~', "/drug/{$slug}", self::for('x')) ?? null;
    }
}
