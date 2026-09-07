<?php

declare(strict_types=1);

namespace App\Domain\Clinic\Policies;

use App\Domain\Clinic\Enums\Permission;
use App\Models\Tenant\Specialty;
use App\Models\Tenant\User;

final class SpecialtyPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active;
    }

    public function view(User $user, Specialty $model): bool
    {
        return $user->is_active;
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::ClinicSpecialtiesManage->value);
    }

    public function update(User $user, Specialty $model): bool
    {
        return $user->can(Permission::ClinicSpecialtiesManage->value);
    }

    public function delete(User $user, Specialty $model): bool
    {
        return $user->can(Permission::ClinicSpecialtiesManage->value);
    }
}
