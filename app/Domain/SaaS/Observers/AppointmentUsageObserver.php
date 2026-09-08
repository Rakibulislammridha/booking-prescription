<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Observers;

use App\Domain\Booking\Enums\AppointmentStatus;
use App\Domain\SaaS\Enums\UsageMetric;
use App\Domain\SaaS\Observers\Concerns\MetersTenantUsage;
use App\Models\Tenant\Appointment;

/**
 * `appointments` is the monthly meter the plan tiers sell (BRIEF §5.M). It is taken at INSERT, inside the booking
 * transaction, so a clinic on 500/month gets its 500th booking and the 501st is refused with the upgrade path —
 * and two receptionists clicking at the same moment cannot both be the 500th.
 *
 * A `draft` row is not a booking: PRESCRIPTION.md §4.8 creates one when a doctor schedules a follow-up, and the
 * patient may never take it. It is metered when it is confirmed, not when it is offered.
 */
final class AppointmentUsageObserver
{
    use MetersTenantUsage;

    public function creating(Appointment $appointment): void
    {
        if ($appointment->status !== AppointmentStatus::Draft) {
            $this->reserve(UsageMetric::Appointments);
        }
    }

    public function updating(Appointment $appointment): void
    {
        if ($appointment->getRawOriginal('status') === AppointmentStatus::Draft->value && $appointment->status !== AppointmentStatus::Draft) {
            $this->reserve(UsageMetric::Appointments);
        }
    }
}
