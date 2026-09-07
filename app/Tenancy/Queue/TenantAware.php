<?php

declare(strict_types=1);

namespace App\Tenancy\Queue;

use App\Models\Central\Tenant;
use App\Tenancy\Facades\Tenancy;
use App\Tenancy\Queue\Middleware\InitializeTenancyForJob;

/**
 * For jobs/listeners/notifications that are explicitly tenant-bound (ARCHITECTURE §4.6).
 * Dispatch from a scheduler as dispatch((new SendReminders)->forTenant($tenant)).
 */
trait TenantAware
{
    public ?int $tenantId = null;                                     // serialised with the job

    public function forTenant(Tenant|int $tenant): static
    {
        $this->tenantId = $tenant instanceof Tenant ? $tenant->id : $tenant;

        return $this;
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [new InitializeTenancyForJob];
    }

    /** @return array<int, string> */
    public function tags(): array
    {
        return ['tenant:'.($this->tenantId ?? Tenancy::id() ?? 'central')];   // Horizon
    }
}
