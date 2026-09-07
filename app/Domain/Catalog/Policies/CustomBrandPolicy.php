<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Policies;

use App\Domain\Clinic\Enums\Permission;
use App\Domain\Clinic\Enums\Role;
use App\Models\Tenant\CustomBrand;
use App\Models\Tenant\User;

/**
 * Doctors (prescriptions.write) and hospital admins manage custom brands; anyone active can read them (they appear in
 * autocomplete). Deleting is admin-only because prescriptions may already reference the row.
 */
final class CustomBrandPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active;
    }

    public function view(User $user, CustomBrand $model): bool
    {
        return $user->is_active;
    }

    public function create(User $user): bool
    {
        return $user->is_active && ($user->can(Permission::PrescriptionsWrite->value) || $user->hasRole(Role::HospitalAdmin->value));
    }

    public function update(User $user, CustomBrand $model): bool
    {
        return $this->create($user);
    }

    public function delete(User $user, CustomBrand $model): bool
    {
        return $user->is_active && $user->hasRole(Role::HospitalAdmin->value);
    }
}
