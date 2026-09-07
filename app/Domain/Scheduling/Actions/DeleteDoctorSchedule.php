<?php

declare(strict_types=1);

namespace App\Domain\Scheduling\Actions;

use App\Domain\Scheduling\Services\SessionMaterialiser;
use App\Domain\Shared\Actor;
use App\Models\Tenant\DoctorSchedule;
use App\Models\Tenant\SessionInstance;
use App\Support\Clock;

/** Deactivates the template (history stays) and cancels its untouched future instances through resync. */
final class DeleteDoctorSchedule
{
    public function __construct(private readonly SessionMaterialiser $materialiser) {}

    public function handle(DoctorSchedule $schedule, Actor $actor): void
    {
        $schedule->forceFill(['is_active' => false, 'effective_to' => Clock::today()->subDay()->toDateString()])->save();

        $instances = SessionInstance::query()
            ->where('doctor_schedule_id', $schedule->id)
            ->whereDate('session_date', '>=', Clock::today()->toDateString())
            ->open()
            ->get();

        foreach ($instances as $instance) {
            $this->materialiser->resync($instance, $actor);
        }
    }
}
