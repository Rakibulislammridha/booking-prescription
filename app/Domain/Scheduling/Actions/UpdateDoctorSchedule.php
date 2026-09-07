<?php

declare(strict_types=1);

namespace App\Domain\Scheduling\Actions;

use App\Domain\Scheduling\Data\DoctorScheduleData;
use App\Domain\Scheduling\Services\SessionMaterialiser;
use App\Domain\Shared\Actor;
use App\Models\Tenant\DoctorSchedule;
use App\Models\Tenant\SessionInstance;
use App\Support\Clock;
use Illuminate\Support\Facades\DB;

/** Updates the template and resyncs its untouched future instances (touched ones are left for override actions). */
final class UpdateDoctorSchedule
{
    public function __construct(private readonly SessionMaterialiser $materialiser) {}

    public function handle(DoctorSchedule $schedule, DoctorScheduleData $data, Actor $actor): DoctorSchedule
    {
        $schedule = DB::transaction(function () use ($schedule, $data): DoctorSchedule {
            CreateDoctorSchedule::assertNoOverlap($data, $schedule->id);
            $schedule->fill($data->toAttributes())->save();

            return $schedule;
        });

        $instances = SessionInstance::query()
            ->where('doctor_schedule_id', $schedule->id)
            ->whereDate('session_date', '>=', Clock::today()->toDateString())
            ->open()
            ->get();

        foreach ($instances as $instance) {
            $this->materialiser->resync($instance, $actor);
        }

        return $schedule;
    }
}
