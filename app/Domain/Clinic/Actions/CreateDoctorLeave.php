<?php

declare(strict_types=1);

namespace App\Domain\Clinic\Actions;

use App\Domain\Clinic\Data\DoctorLeaveData;
use App\Domain\Clinic\Events\DoctorLeaveCreated;
use App\Domain\Clinic\Exceptions\LeaveOverlaps;
use App\Domain\Shared\Actor;
use App\Models\Tenant\DoctorLeave;
use Illuminate\Support\Facades\DB;

/**
 * Planned leave or emergency cancellation range. Scheduling cancels session instances in range and Notifications
 * fans out to booked patients on DoctorLeaveCreated (after commit).
 */
final class CreateDoctorLeave
{
    public function handle(DoctorLeaveData $data, Actor $actor): DoctorLeave
    {
        return DB::transaction(function () use ($data, $actor): DoctorLeave {
            $overlaps = DoctorLeave::query()
                ->where('doctor_id', $data->doctorId)
                ->where('is_cancelled', false)
                ->where(fn ($q) => $data->branchId === null ? $q : $q->where(fn ($b) => $b->whereNull('branch_id')->orWhere('branch_id', $data->branchId)))
                ->whereDate('starts_on', '<=', $data->endsOn->toDateString())
                ->whereDate('ends_on', '>=', $data->startsOn->toDateString())
                ->exists();

            if ($overlaps) {
                throw new LeaveOverlaps;
            }

            $leave = DoctorLeave::query()->create([
                'doctor_id' => $data->doctorId,
                'branch_id' => $data->branchId,
                'starts_on' => $data->startsOn->toDateString(),
                'ends_on' => $data->endsOn->toDateString(),
                'type' => $data->type,
                'reason' => $data->reason,
                'notify_patients' => $data->notifyPatients,
                'is_cancelled' => false,
                'created_by_user_id' => $actor->userId,
            ]);

            event(new DoctorLeaveCreated($leave));

            return $leave;
        });
    }
}
