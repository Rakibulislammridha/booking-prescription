<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Policies;

use App\Domain\Clinic\Enums\Permission;
use App\Models\Tenant\Prescription;
use App\Models\Tenant\User;

/** Draft edits / issue belong to the prescribing doctor; amend/void to the issuing doctor or `prescriptions.view.any` + write. */
final class PrescriptionPolicy
{
    /**
     * The index (`panel.prescriptions.index`): the same three grants `view()` accepts per row — the wider permission,
     * a prescriber, or a compounder — so a role that could open no row cannot open the list either (accountants).
     * Which rows a restricted doctor then sees is PrescriptionIndexQuery's PatientAccessResolver constraint.
     */
    public function viewAny(User $user): bool
    {
        return $user->is_active && ($user->can(Permission::PrescriptionsViewAny->value) || $user->can(Permission::PrescriptionsWrite->value) || $user->can(Permission::PrescriptionsVitalsRecord->value));
    }

    public function view(User $user, Prescription $rx): bool
    {
        return $user->is_active && ($user->can(Permission::PrescriptionsViewAny->value) || $this->owns($user, $rx) || $user->can(Permission::PrescriptionsVitalsRecord->value));
    }

    public function write(User $user, Prescription $rx): bool
    {
        return $user->is_active && $user->can(Permission::PrescriptionsWrite->value) && $this->owns($user, $rx);
    }

    public function issue(User $user, Prescription $rx): bool
    {
        return $this->write($user, $rx);
    }

    public function amend(User $user, Prescription $rx): bool
    {
        return $user->is_active && $user->can(Permission::PrescriptionsWrite->value) && ($this->owns($user, $rx) || $user->can(Permission::PrescriptionsViewAny->value));
    }

    public function void(User $user, Prescription $rx): bool
    {
        return $this->amend($user, $rx);
    }

    public function delete(User $user, Prescription $rx): bool
    {
        return $this->write($user, $rx);
    }

    public function send(User $user, Prescription $rx): bool
    {
        return $this->view($user, $rx);
    }

    private function owns(User $user, Prescription $rx): bool
    {
        $doctorId = $user->doctor()->value('id');

        return $doctorId !== null && (int) $doctorId === $rx->doctor_id;
    }
}
