<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Observers;

use App\Domain\SaaS\Enums\UsageMetric;
use App\Domain\SaaS\Observers\Concerns\MetersTenantUsage;
use App\Models\Tenant\Branch;

/**
 * `branches` is a gauge (SCHEMA §2.10): it counts the ACTIVE branches that exist, so deactivating one frees a
 * seat and reactivating one has to ask for it back — otherwise "1 branch" would be a one-way ratchet a clinic
 * could dodge by deleting and recreating.
 */
final class BranchUsageObserver
{
    use MetersTenantUsage;

    public function creating(Branch $branch): void
    {
        if ($branch->is_active !== false) {
            $this->reserve(UsageMetric::Branches);
        }
    }

    public function updated(Branch $branch): void
    {
        if (! $branch->wasChanged('is_active')) {
            return;
        }

        $branch->is_active ? $this->reserve(UsageMetric::Branches) : $this->release(UsageMetric::Branches);
    }

    public function deleted(Branch $branch): void
    {
        // A force-delete of an already-trashed row fires `deleted` a second time; it freed its seat the first time.
        if ($branch->isForceDeleting() && $branch->getOriginal('deleted_at') !== null) {
            return;
        }

        if ($branch->getOriginal('is_active') !== false) {
            $this->release(UsageMetric::Branches);
        }
    }

    public function restored(Branch $branch): void
    {
        if ($branch->is_active) {
            $this->reserve(UsageMetric::Branches);
        }
    }
}
