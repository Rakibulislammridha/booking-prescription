<?php

declare(strict_types=1);

namespace App\Domain\Booking\Policies;

use App\Domain\Clinic\Enums\Permission;
use App\Domain\Clinic\Enums\Role;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\User;

/** Staff authorisation on bookings: issuing follows the counter permission, cancelling the serials.cancel one. */
final class AppointmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->is_active;
    }

    public function view(User $user, Appointment $appointment): bool
    {
        return $user->is_active;
    }

    public function create(User $user): bool
    {
        return $user->is_active && ($user->can(Permission::SerialsIssueCounter->value) || $user->hasRole(Role::Doctor->value));
    }

    public function cancel(User $user, Appointment $appointment): bool
    {
        return $user->is_active && ($user->can(Permission::SerialsCancel->value) || $user->hasRole(Role::HospitalAdmin->value));
    }

    public function reschedule(User $user, Appointment $appointment): bool
    {
        return $user->is_active && ($user->can(Permission::SerialsIssueCounter->value) || $user->can(Permission::SerialsTransfer->value) || $user->hasRole(Role::Doctor->value));
    }

    public function collect(User $user, Appointment $appointment): bool
    {
        return $user->is_active && $user->can(Permission::BillingPaymentsCollect->value);
    }
}
