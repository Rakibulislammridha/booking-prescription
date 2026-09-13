<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Policies;

use App\Domain\Clinic\Enums\Permission;
use App\Domain\Clinic\Services\DoctorScope;
use App\Models\Tenant\Prescription;
use App\Models\Tenant\User;

/**
 * Draft edits / issue belong to the prescribing doctor; amend/void to the issuing doctor or `prescriptions.view.any` + write.
 *
 * Reading is the wide door: `prescriptions.vitals.record` grants `view`, because BRIEF §5.G.4 has the sheet printed
 * at the desk, and `view` is what the print, pharmacy-copy, PDF, attachment and `pdf.ready` channel routes all
 * delegate to. For a user DoctorScope restricts, that door is narrowed to the doctors they were assigned to: a
 * compounder still prints their own doctor's sheet at the desk — the point of the feature — and reads nothing of a
 * colleague's. Sending the sheet to the patient is excluded outright, see `send()`.
 */
final class PrescriptionPolicy
{
    public function __construct(private readonly DoctorScope $scope) {}

    /**
     * The index (`panel.prescriptions.index`): the same three grants `view()` accepts per row — the wider permission,
     * a prescriber, or a compounder — so a role that could open no row cannot open the list either (accountants).
     * Which rows a restricted doctor then sees is PrescriptionIndexQuery's PatientAccessResolver constraint.
     */
    public function viewAny(User $user): bool
    {
        // A restricted user with no doctor at all (an unassigned compounder) has no list to open, not an empty one:
        // DoctorScope's empty list is a deny everywhere, never a no-op.
        return $user->is_active
            && $this->scope->doctorIds($user) !== []
            && ($user->can(Permission::PrescriptionsViewAny->value) || $user->can(Permission::PrescriptionsWrite->value) || $user->can(Permission::PrescriptionsVitalsRecord->value));
    }

    public function view(User $user, Prescription $rx): bool
    {
        return $user->is_active
            && $this->scope->allows($user, $rx->doctor_id)
            && ($user->can(Permission::PrescriptionsViewAny->value) || $this->owns($user, $rx) || $user->can(Permission::PrescriptionsVitalsRecord->value));
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

    /**
     * Messaging the sheet to the patient (SMS / WhatsApp / e-mail). Reading it at the desk and sending it in the
     * clinic's name are different acts: a restricted user may print their doctor's sheet but never speaks to the
     * patient on the doctor's behalf, so the scope here is not "which doctors" but "restricted at all".
     */
    public function send(User $user, Prescription $rx): bool
    {
        return $this->scope->doctorIds($user) === null && $this->view($user, $rx);
    }

    private function owns(User $user, Prescription $rx): bool
    {
        $doctorId = $user->doctor()->value('id');

        return $doctorId !== null && (int) $doctorId === $rx->doctor_id;
    }
}
