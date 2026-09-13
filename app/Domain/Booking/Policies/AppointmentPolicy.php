<?php

declare(strict_types=1);

namespace App\Domain\Booking\Policies;

use App\Domain\Clinic\Enums\Permission;
use App\Domain\Clinic\Enums\Role;
use App\Domain\Clinic\Services\DoctorScope;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\User;

/**
 * Staff authorisation on bookings: issuing follows the counter permission, cancelling the serials.cancel one.
 *
 * `viewAny` is the desk's door (the board, its JSON poll, the print templates) and stays "any active staff user":
 * WHAT the board then contains is narrowed by BoardBuilder from the caller's DoctorScope, because a board is a list
 * and a list is filtered, not refused. Every ability that names ONE booking asks DoctorScope directly — `view` and
 * `collect`, and also `cancel` and `reschedule`, which were first left on their permissions alone. That was only
 * ever true of the ROLE: an account holding compounder AND receptionist read a doctor-scoped board and could then
 * cancel or move any booking on it from the receptionist half, and the cancel response hands back the booking. The
 * conjunct is free for everyone else — doctorIds() is null for them, so allows() is true.
 */
final class AppointmentPolicy
{
    public function __construct(private readonly DoctorScope $scope) {}

    public function viewAny(User $user): bool
    {
        return $user->is_active;
    }

    public function view(User $user, Appointment $appointment): bool
    {
        return $user->is_active && $this->scope->allows($user, $appointment->doctor_id);
    }

    public function create(User $user): bool
    {
        return $user->is_active && ($user->can(Permission::SerialsIssueCounter->value) || $user->hasRole(Role::Doctor->value));
    }

    public function cancel(User $user, Appointment $appointment): bool
    {
        return $user->is_active
            && ($user->can(Permission::SerialsCancel->value) || $user->hasRole(Role::HospitalAdmin->value))
            && $this->scope->allows($user, $appointment->doctor_id);
    }

    public function reschedule(User $user, Appointment $appointment): bool
    {
        return $user->is_active
            && ($user->can(Permission::SerialsIssueCounter->value) || $user->can(Permission::SerialsTransfer->value) || $user->hasRole(Role::Doctor->value))
            && $this->scope->allows($user, $appointment->doctor_id);
    }

    public function collect(User $user, Appointment $appointment): bool
    {
        return $user->is_active
            && $user->can(Permission::BillingPaymentsCollect->value)
            && $this->scope->allows($user, $appointment->doctor_id);
    }
}
