<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Policies;

use App\Domain\Clinic\Enums\Permission;
use App\Domain\Clinic\Services\DoctorScope;
use App\Models\Tenant\PrescriptionTemplate;
use App\Models\Tenant\User;

/**
 * Own templates + clinic-shared + other doctors' `is_shared`; edit/delete = owner or `prescriptions.view.any` (admin).
 *
 * `view` was "any active staff user" and a clinic-shared or `is_shared` template was therefore readable by anyone
 * who could sign in — a compounder included, who could page through the clinic's prescribing content. The first fix
 * for that demanded `prescriptions.write` or `prescriptions.view.any`, which closed the hole and also took the
 * shared-template list away from the RECEPTIONIST, who had always had it and was never in question. (No test covered
 * either direction, which is why the suites stayed green — green meant untested, not unchanged.)
 *
 * So the rule is the original one again, refused to DoctorScope-RESTRICTED users only: `doctorIds()` answers null for
 * everyone who is not a compounder, and a non-null answer — a list, or the empty list of an unassigned compounder —
 * is the whole of who loses this. It reads the scope rather than the role so that "restricted" keeps meaning one
 * thing across the codebase.
 */
final class PrescriptionTemplatePolicy
{
    public function __construct(private readonly DoctorScope $scope) {}

    public function view(User $user, PrescriptionTemplate $template): bool
    {
        $doctorId = $user->doctor()->value('id');

        return $user->is_active
            && $this->scope->doctorIds($user) === null
            && ($template->doctor_id === null || $template->is_shared || ($doctorId !== null && (int) $doctorId === $template->doctor_id) || $user->can(Permission::PrescriptionsViewAny->value));
    }

    public function create(User $user): bool
    {
        return $user->is_active && $user->can(Permission::PrescriptionsWrite->value);
    }

    public function update(User $user, PrescriptionTemplate $template): bool
    {
        $doctorId = $user->doctor()->value('id');

        return $user->is_active && $user->can(Permission::PrescriptionsWrite->value) && (($doctorId !== null && (int) $doctorId === $template->doctor_id) || $user->can(Permission::PrescriptionsViewAny->value));
    }

    public function delete(User $user, PrescriptionTemplate $template): bool
    {
        return $this->update($user, $template);
    }
}
