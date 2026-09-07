<?php

declare(strict_types=1);

namespace App\Domain\Clinic\Policies;

use App\Domain\Clinic\Enums\Permission;
use App\Models\Tenant\Holiday;
use App\Models\Tenant\User;

final class HolidayPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active;
    }

    public function view(User $user, Holiday $model): bool
    {
        return $user->is_active;
    }

    public function create(User $user): bool
    {
        return $user->can(Permission::ClinicHolidaysManage->value);
    }

    public function update(User $user, Holiday $model): bool
    {
        return $user->can(Permission::ClinicHolidaysManage->value);
    }

    public function delete(User $user, Holiday $model): bool
    {
        return $user->can(Permission::ClinicHolidaysManage->value);
    }
}
