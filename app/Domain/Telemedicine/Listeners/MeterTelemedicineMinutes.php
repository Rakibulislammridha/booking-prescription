<?php

declare(strict_types=1);

namespace App\Domain\Telemedicine\Listeners;

use App\Domain\SaaS\Enums\UsageMetric;
use App\Domain\SaaS\Services\UsageMeter;
use App\Domain\Telemedicine\Events\CallEnded;
use App\Models\Central\Tenant;

/**
 * SCHEMA §3.8: `duration_seconds` is "billed to `usage_counters.telemedicine_minutes`". Rounded UP, minimum one
 * minute for any call that actually connected — a clinic is not billed for a call that never started, and a
 * 40-second consultation is a minute like every telecom on earth counts it.
 */
final class MeterTelemedicineMinutes
{
    public function __construct(private readonly UsageMeter $meter) {}

    public function handle(CallEnded $event): void
    {
        if ($event->durationSeconds <= 0) {
            return;
        }

        $tenant = Tenant::query()->find($event->tenantId);

        if ($tenant === null) {
            return;
        }

        $this->meter->add($tenant, UsageMetric::TelemedicineMinutes, (int) ceil($event->durationSeconds / 60));
    }
}
