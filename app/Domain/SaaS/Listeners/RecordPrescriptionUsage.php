<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Listeners;

use App\Domain\Prescription\Events\PrescriptionIssued;
use App\Domain\SaaS\Enums\UsageMetric;
use App\Domain\SaaS\Services\UsageMeter;
use App\Models\Central\Tenant;

/**
 * ARCHITECTURE §5.4: SaaS meters `prescriptions` off `PrescriptionIssued`.
 *
 * No plan caps prescriptions, so an after-commit listener is exactly right here: nothing has to be refused, only
 * counted, and counting the ISSUED ones rather than the drafts is what makes the number mean something on a
 * usage dashboard.
 */
final class RecordPrescriptionUsage
{
    public function __construct(private readonly UsageMeter $meter) {}

    public function handle(PrescriptionIssued $event): void
    {
        $tenant = Tenant::query()->find($event->tenantId);

        if ($tenant !== null) {
            $this->meter->add($tenant, UsageMetric::Prescriptions, 1);
        }
    }
}
