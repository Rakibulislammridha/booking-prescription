<?php

declare(strict_types=1);

namespace App\Domain\Reception\Policies;

use App\Domain\Clinic\Enums\Permission;
use App\Models\Tenant\ReceptionDevice;
use App\Models\Tenant\User;

final class ReceptionDevicePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active && $user->can(Permission::ReceptionDevicesRegister->value);
    }

    public function register(User $user): bool
    {
        return $this->viewAny($user);
    }

    public function revoke(User $user, ReceptionDevice $device): bool
    {
        return $user->is_active && $user->can(Permission::ReceptionBlocksRevoke->value);
    }
}
