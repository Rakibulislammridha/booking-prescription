<?php

declare(strict_types=1);

namespace App\Domain\Scheduling\Actions;

use App\Domain\Scheduling\Data\DoctorScheduleData;
use App\Domain\Scheduling\Exceptions\ScheduleConflict;
use App\Domain\Shared\Actor;
use App\Models\Tenant\DoctorSchedule;
use App\Support\Clock;
use Illuminate\Support\Facades\DB;

/** Weekly template row; overlapping times on the same weekday/branch are refused. Affects future materialisations only. */
final class CreateDoctorSchedule
{
    public function handle(DoctorScheduleData $data, Actor $actor): DoctorSchedule
    {
        return DB::transaction(function () use ($data): DoctorSchedule {
            self::assertNoOverlap($data);

            return DoctorSchedule::query()->create($data->toAttributes());
        });
    }

    public static function assertNoOverlap(DoctorScheduleData $data, ?int $ignoreId = null): void
    {
        $query = DoctorSchedule::query()
            ->where('doctor_id', $data->doctorId)->where('branch_id', $data->branchId)->where('weekday', $data->weekday)
            ->where('is_active', true)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', ($data->effectiveFrom ?? Clock::today())->toDateString()));

        if ($ignoreId !== null) {
            $query->where('id', '<>', $ignoreId);
        }

        foreach ($query->get() as $other) {
            if ($other->session_code === $data->sessionCode) {
                throw new ScheduleConflict("Session {$data->sessionCode} already exists on that weekday.");
            }

            if ($other->start_time < $data->endTime && $data->startTime < $other->end_time) {
                throw new ScheduleConflict("Session {$data->sessionCode} overlaps session {$other->session_code} in time.");
            }
        }
    }
}
