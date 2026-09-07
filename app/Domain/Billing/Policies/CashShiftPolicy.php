<?php

declare(strict_types=1);

namespace App\Domain\Billing\Policies;

use App\Domain\Clinic\Enums\Permission;
use App\Domain\Clinic\Enums\Role;
use App\Models\Tenant\CashShift;
use App\Models\Tenant\User;

/** Anyone who takes counter money owns a drawer; only a supervisor may close somebody else's. */
final class CashShiftPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active && ($user->can(Permission::BillingPaymentsCollect->value) || $user->can(Permission::BillingReportsView->value));
    }

    public function view(User $user, CashShift $shift): bool
    {
        return $this->viewAny($user) && ($shift->user_id === $user->id || $this->isSupervisor($user));
    }

    public function open(User $user): bool
    {
        return $user->is_active && $user->can(Permission::BillingPaymentsCollect->value);
    }

    public function close(User $user, CashShift $shift): bool
    {
        return $user->is_active && $user->can(Permission::BillingPaymentsCollect->value) && ($shift->user_id === $user->id || $this->isSupervisor($user));
    }

    private function isSupervisor(User $user): bool
    {
        return $user->hasRole(Role::HospitalAdmin->value) || $user->can(Permission::BillingReportsView->value);
    }
}
