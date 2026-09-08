<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Observers;

use App\Domain\SaaS\Enums\UsageMetric;
use App\Domain\SaaS\Observers\Concerns\MetersTenantUsage;
use App\Models\Tenant\Doctor;

/** `doctors` is a gauge over the active doctors — the seat count a plan actually sells. */
final class DoctorUsageObserver
{
    use MetersTenantUsage;

    public function creating(Doctor $doctor): void
    {
        if ($doctor->is_active !== false) {
            $this->reserve(UsageMetric::Doctors);
        }
    }

    public function updated(Doctor $doctor): void
    {
        if (! $doctor->wasChanged('is_active')) {
            return;
        }

        $doctor->is_active ? $this->reserve(UsageMetric::Doctors) : $this->release(UsageMetric::Doctors);
    }

    public function deleted(Doctor $doctor): void
    {
        if ($doctor->isForceDeleting() && $doctor->getOriginal('deleted_at') !== null) {
            return;
        }

        if ($doctor->getOriginal('is_active') !== false) {
            $this->release(UsageMetric::Doctors);
        }
    }

    public function restored(Doctor $doctor): void
    {
        if ($doctor->is_active) {
            $this->reserve(UsageMetric::Doctors);
        }
    }
}
