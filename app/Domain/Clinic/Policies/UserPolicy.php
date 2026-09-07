<?php

declare(strict_types=1);

namespace App\Domain\Clinic\Policies;

use App\Domain\Clinic\Enums\Permission;
use App\Models\Tenant\User;

final class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Permission::ClinicUsersManage->value);
    }

    public function view(User $user, User $model): bool
    {
        return $user->is($model) || $user->can(Permission::ClinicUsersManage->value);
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::ClinicUsersManage->value);
    }

    public function update(User $user, User $model): bool
    {
        return $user->can(Permission::ClinicUsersManage->value);
    }

    public function delete(User $user, User $model): bool
    {
        return ! $user->is($model) && $user->can(Permission::ClinicUsersManage->value);
    }
}
