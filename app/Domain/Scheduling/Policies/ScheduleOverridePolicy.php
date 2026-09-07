<?php

declare(strict_types=1);

namespace App\Domain\Scheduling\Policies;

use App\Domain\Clinic\Enums\Permission;
use App\Models\Tenant\ScheduleOverride;
use App\Models\Tenant\User;

final class ScheduleOverridePolicy
{
    public function viewAny(User $user): bool
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

    public function delete(User $user, ScheduleOverride $override): bool
    {
        return $this->create($user, $override->doctor_id);
    }
}
