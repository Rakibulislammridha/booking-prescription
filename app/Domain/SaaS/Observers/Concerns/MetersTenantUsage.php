<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Observers\Concerns;

use App\Domain\SaaS\Enums\UsageMetric;
use App\Domain\SaaS\Services\PlanLimits;
use App\Models\Central\Tenant;
use App\Tenancy\Facades\Tenancy;

/**
 * Shared plumbing for the six observers that meter a tenant's writes.
 *
 * Why observers and not the Actions ARCHITECTURE §8.4 names: an `AppointmentBooked` listener fires AFTER commit,
 * which is too late to refuse anything, and a check inside one Action is only as good as the number of write paths
 * that go through that Action. `Model::creating` is on every Eloquent write path in the process — the panel, the
 * public site, the offline replay, a console command — so the cap cannot be walked around, and it runs inside the
 * caller's transaction so a failed write un-counts itself.
 *
 * Without an active tenancy there is nothing to meter (central requests, migrations, the provisioning of a schema
 * before the tenant row is committed) and every hook is a no-op.
 */
trait MetersTenantUsage
{
    protected function tenant(): ?Tenant
    {
        return Tenancy::current();
    }

    protected function limits(): PlanLimits
    {
        return app(PlanLimits::class);
    }

    /** Atomic gate: throws `PlanLimitExceeded` when this write would cross the plan's cap. */
    protected function reserve(UsageMetric $metric, int $qty = 1): void
    {
        $tenant = $this->tenant();

        if ($tenant !== null && $qty > 0) {
            $this->limits()->reserve($tenant, $metric, $qty);
        }
    }

    protected function release(UsageMetric $metric, int $qty = 1): void
    {
        $tenant = $this->tenant();

        if ($tenant !== null && $qty > 0) {
            $this->limits()->release($tenant, $metric, $qty);
        }
    }
}
