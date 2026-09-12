<?php

declare(strict_types=1);

namespace App\Domain\Serials\Policies;

use App\Domain\Clinic\Enums\Permission;
use App\Domain\Clinic\Enums\Role;
use App\Domain\Clinic\Services\BranchAccess;
use App\Models\Tenant\Serial;
use App\Models\Tenant\User;

/** Serial-level authorisation per the "Who" column of SERIAL_ENGINE §6. */
final class SerialPolicy
{
    public function __construct(private readonly BranchAccess $branches) {}

    public function view(User $user, Serial $serial): bool
    {
        return $user->is_active;
    }

    /**
     * The desk recording vitals on this serial (BRIEF §5.G.2, `prescriptions.vitals.record`). Unlike `view`, this
     * is scoped: the patient must be in the building (checked in or in the chamber — SerialStatus::isPresent, the
     * same rule that puts the button on the board row) and the serial must sit at a branch this user acts for
     * (BranchAccess). A receptionist on the main desk does not open encounters for a booked serial at another
     * branch.
     */
    public function recordVitals(User $user, Serial $serial): bool
    {
        return $user->is_active
            && $user->can(Permission::PrescriptionsVitalsRecord->value)
            && $serial->status->isPresent()
            && $this->branches->actsFor($user, (int) $serial->sessionInstance->branch_id);
    }

    public function checkIn(User $user, Serial $serial): bool
    {
        return $user->is_active && ($user->hasRole(Role::Receptionist->value) || $user->hasRole(Role::HospitalAdmin->value) || $user->hasRole(Role::Doctor->value));
    }

    /**
     * Call / skip / return (SERIAL_ENGINE §6 "Doctor, Reception"): `queue.call-next`, and — the doctor-channel rule of
     * ChannelGuards::doctor — a user who IS a doctor only drives their own session. The permission opens every
     * chamber to an operator (reception, admin), never a colleague's chamber to a doctor.
     */
    public function call(User $user, Serial $serial): bool
    {
        return $user->can(Permission::QueueCallNext->value)
            && SessionInstancePolicy::drivesSession($user, (int) $serial->sessionInstance->doctor_id);
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
