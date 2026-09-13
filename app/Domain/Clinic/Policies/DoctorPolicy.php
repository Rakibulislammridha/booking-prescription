<?php

declare(strict_types=1);

namespace App\Domain\Clinic\Policies;

use App\Domain\Clinic\Enums\Permission;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\User;

final class DoctorPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active;
    }

    public function view(User $user, Doctor $doctor): bool
    {
        return $user->is_active;
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::ClinicDoctorsManage->value);
    }

    public function update(User $user, Doctor $doctor): bool
    {
        return $user->can(Permission::ClinicDoctorsManage->value);
    }

    public function delete(User $user, Doctor $doctor): bool
    {
        return $user->can(Permission::ClinicDoctorsManage->value);
    }

    /**
     * Who may put a compounder on this doctor's desk. Same shape as designPad, and for the same reason: a doctor
     * administers their own row, an admin administers every row. `clinic.pad.design` is the permission a doctor
     * already holds for exactly that kind of self-administration, so the compounder list rides on it rather than
     * inventing a second one.
     */
    public function manageCompounders(User $user, Doctor $doctor): bool
    {
        if ($user->can(Permission::ClinicDoctorsManage->value)) {
            return true;
        }

        return $doctor->user_id === $user->id && $user->can(Permission::ClinicPadDesign->value);
    }

    /** The doctor designs their own pad; admins design any. */
    public function designPad(User $user, Doctor $doctor): bool
    {
        if ($user->can(Permission::ClinicDoctorsManage->value)) {
            return true;
        }

        return $doctor->user_id === $user->id && $user->can(Permission::ClinicPadDesign->value);
    }
}
