<?php

declare(strict_types=1);

namespace App\Tenancy\Queue\Middleware;

use App\Models\Central\Tenant;
use App\Tenancy\Exceptions\TenantMismatch;
use App\Tenancy\Facades\Tenancy;
use Closure;

/**
 * Never silently switches tenants: an active tenant must equal the job's tenant; otherwise initialise (and end) it.
 */
final class InitializeTenancyForJob
{
    public function handle(object $job, Closure $next): mixed
    {
        $tenantId = property_exists($job, 'tenantId') ? $job->tenantId : null;

        if (Tenancy::check()) {
            if ($tenantId !== null && Tenancy::id() !== $tenantId) {
                throw new TenantMismatch(Tenancy::id(), $tenantId);
            }

            return $next($job);
        }

        if ($tenantId === null) {
            return $next($job);                                       // a central job
        }

        Tenancy::initialize(Tenant::query()->findOrFail($tenantId));

        try {
            return $next($job);
        } finally {
            Tenancy::end();
        }
    }
}
