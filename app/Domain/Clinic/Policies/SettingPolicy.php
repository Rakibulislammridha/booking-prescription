<?php

declare(strict_types=1);

namespace App\Domain\Clinic\Policies;

use App\Domain\Clinic\Enums\Permission;
use App\Models\Tenant\Setting;
use App\Models\Tenant\User;

final class SettingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active;
    }

    public function view(User $user, Setting $model): bool
    {
        return $user->is_active;
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::ClinicSettingsManage->value);
    }

    public function update(User $user, Setting $model): bool
    {
        return $user->can(Permission::ClinicSettingsManage->value);
    }

    public function delete(User $user, Setting $model): bool
    {
        return $user->can(Permission::ClinicSettingsManage->value);
    }
}
