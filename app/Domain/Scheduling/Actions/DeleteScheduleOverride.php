<?php

declare(strict_types=1);

namespace App\Domain\Scheduling\Actions;

use App\Domain\Scheduling\Services\SessionMaterialiser;
use App\Domain\Shared\Actor;
use App\Models\Tenant\ScheduleOverride;
use App\Models\Tenant\SessionInstance;

/** Removes the override and resyncs the day's untouched instances back to the template (a cancelled instance stays cancelled). */
final class DeleteScheduleOverride
{
    public function __construct(private readonly SessionMaterialiser $materialiser) {}

    public function handle(ScheduleOverride $override, Actor $actor): void
    {
        $override->delete();

        $instances = SessionInstance::query()
            ->where('doctor_id', $override->doctor_id)->where('branch_id', $override->branch_id)
            ->whereDate('session_date', $override->override_date->toDateString())
            ->open()
            ->get();

        foreach ($instances as $instance) {
            $this->materialiser->resync($instance, $actor);
        }
    }
}
