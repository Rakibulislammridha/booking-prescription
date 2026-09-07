<?php

declare(strict_types=1);

namespace App\Domain\Billing\Policies;

use App\Domain\Clinic\Enums\Permission;
use App\Domain\Clinic\Enums\Role;
use App\Models\Tenant\DoctorRevenueShare;
use App\Models\Tenant\User;

/** Commission rules are money policy: read with the reports permission, written by the hospital admin. */
final class DoctorRevenueSharePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active && ($user->can(Permission::BillingReportsView->value) || $user->hasRole(Role::Doctor->value));
    }

    public function view(User $user, DoctorRevenueShare $share): bool
    {
        if (! $user->is_active) {
            return false;
        }

        if ($user->can(Permission::BillingReportsView->value)) {
            return true;
        }

        return $user->hasRole(Role::Doctor->value) && $share->doctor_id === $user->doctor?->id;
    }

    public function create(User $user): bool
    {
        return $user->is_active && $user->hasRole(Role::HospitalAdmin->value);
    }

    public function update(User $user, DoctorRevenueShare $share): bool
    {
        return $this->create($user);
    }

    public function delete(User $user, DoctorRevenueShare $share): bool
    {
        return $this->create($user);
    }
}
