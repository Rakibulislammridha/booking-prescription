<?php

declare(strict_types=1);

namespace App\Domain\Serials\Policies;

use App\Domain\Clinic\Enums\Permission;
use App\Domain\Clinic\Enums\Role;
use App\Models\Tenant\Serial;
use App\Models\Tenant\User;

/** Serial-level authorisation per the "Who" column of SERIAL_ENGINE §6. */
final class SerialPolicy
{
    public function view(User $user, Serial $serial): bool
    {
        return $user->is_active;
    }

    public function checkIn(User $user, Serial $serial): bool
    {
        return $user->is_active && ($user->hasRole(Role::Receptionist->value) || $user->hasRole(Role::HospitalAdmin->value) || $user->hasRole(Role::Doctor->value));
    }

    public function call(User $user, Serial $serial): bool
    {
        return $user->can(Permission::QueueCallNext->value);
    }

    public function complete(User $user, Serial $serial): bool
    {
        return $user->hasRole(Role::Doctor->value) || $user->can(Permission::QueueCallNext->value);
    }

    public function noShow(User $user, Serial $serial): bool
    {
        return $this->checkIn($user, $serial);
    }

    public function reinstate(User $user, Serial $serial): bool
    {
        return $this->checkIn($user, $serial);
    }

    public function cancel(User $user, Serial $serial): bool
    {
        return $user->can(Permission::SerialsCancel->value) || $user->hasRole(Role::HospitalAdmin->value);
    }

    public function postpone(User $user, Serial $serial): bool
    {
        return $this->checkIn($user, $serial);
    }

    public function transfer(User $user, Serial $serial): bool
    {
        return $user->can(Permission::SerialsTransfer->value) || $user->hasRole(Role::Doctor->value);
    }

    public function reorder(User $user, Serial $serial): bool
    {
        return $user->can(Permission::SerialsReorder->value);
    }

    public function priority(User $user, Serial $serial): bool
    {
        return $user->can(Permission::SerialsReorder->value);
    }
}
