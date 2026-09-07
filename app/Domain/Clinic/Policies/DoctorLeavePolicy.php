<?php

declare(strict_types=1);

namespace App\Domain\Clinic\Policies;

use App\Domain\Clinic\Enums\Permission;
use App\Models\Tenant\DoctorLeave;
use App\Models\Tenant\User;

final class DoctorLeavePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active;
    }

    public function view(User $user, DoctorLeave $leave): bool
    {
        return $user->is_active;
    }

    /** Admins manage any doctor's leave; a doctor manages their own (Doctor role holds clinic.leaves.manage). */
    public function create(User $user, ?int $doctorId = null): bool
    {
        if ($user->can(Permission::ClinicDoctorsManage->value)) {
            return true;
        }

        return $user->can(Permission::ClinicLeavesManage->value) && ($doctorId === null || $user->doctor()->value('id') === $doctorId);
    }

    public function update(User $user, DoctorLeave $leave): bool
    {
        return $this->create($user, $leave->doctor_id);
    }

    public function delete(User $user, DoctorLeave $leave): bool
    {
        return $this->create($user, $leave->doctor_id);
    }
}
