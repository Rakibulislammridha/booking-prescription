<?php

declare(strict_types=1);

namespace App\Domain\Clinic\Policies;

use App\Domain\Clinic\Enums\Permission;
use App\Models\Tenant\Branch;
use App\Models\Tenant\User;

final class BranchPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active;
    }

    public function view(User $user, Branch $model): bool
    {
        return $user->is_active;
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::ClinicBranchesManage->value);
    }

    public function update(User $user, Branch $model): bool
    {
        return $user->can(Permission::ClinicBranchesManage->value);
    }

    public function delete(User $user, Branch $model): bool
    {
        return $user->can(Permission::ClinicBranchesManage->value);
    }
}
