<?php

declare(strict_types=1);

namespace App\Domain\Clinic\Policies;

use App\Domain\Clinic\Enums\Permission;
use App\Models\Tenant\Department;
use App\Models\Tenant\User;

final class DepartmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active;
    }

    public function view(User $user, Department $model): bool
    {
        return $user->is_active;
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::ClinicDepartmentsManage->value);
    }

    public function update(User $user, Department $model): bool
    {
        return $user->can(Permission::ClinicDepartmentsManage->value);
    }

    public function delete(User $user, Department $model): bool
    {
        return $user->can(Permission::ClinicDepartmentsManage->value);
    }
}
