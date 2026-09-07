<?php

declare(strict_types=1);

namespace App\Domain\Scheduling\Policies;

use App\Domain\Clinic\Enums\Permission;
use App\Models\Tenant\DoctorSchedule;
use App\Models\Tenant\User;

/** Admins manage any template; a doctor (scheduling.schedules.manage) manages their own. */
final class DoctorSchedulePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active;
    }

    public function view(User $user, DoctorSchedule $schedule): bool
    {
        return $user->is_active;
    }

    public function create(User $user, ?int $doctorId = null): bool
    {
        if ($user->can(Permission::ClinicDoctorsManage->value)) {
            return true;
        }

        return $user->can(Permission::SchedulingSchedulesManage->value) && ($doctorId === null || $user->doctor()->value('id') === $doctorId);
    }

    public function update(User $user, DoctorSchedule $schedule): bool
    {
        return $this->create($user, $schedule->doctor_id);
    }

    public function delete(User $user, DoctorSchedule $schedule): bool
    {
        return $this->create($user, $schedule->doctor_id);
    }
}
