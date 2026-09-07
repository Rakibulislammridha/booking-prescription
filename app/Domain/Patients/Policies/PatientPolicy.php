<?php

declare(strict_types=1);

namespace App\Domain\Patients\Policies;

use App\Domain\Clinic\Enums\Permission;
use App\Domain\Patients\Contracts\PatientAccessResolver;
use App\Models\Tenant\Patient;
use App\Models\Tenant\User;

/**
 * Staff access to patient records (ARCHITECTURE §6.2): the permission gate first, then the row-level
 * PatientAccessResolver (a doctor sees only patients they treat unless `prescriptions.view.any`).
 */
final class PatientPolicy
{
    public function __construct(private readonly PatientAccessResolver $access) {}

    public function viewAny(User $user): bool
    {
        return $user->is_active && $user->can(Permission::PatientsView->value);
    }

    public function view(User $user, Patient $patient): bool
    {
        return $this->viewAny($user) && $this->access->canAccess($user, $patient);
    }

    public function create(User $user): bool
    {
        return $user->is_active && $user->can(Permission::PatientsCreate->value);
    }

    public function update(User $user, Patient $patient): bool
    {
        return $user->is_active && $user->can(Permission::PatientsUpdate->value) && $this->access->canAccess($user, $patient);
    }

    public function merge(User $user, Patient $patient): bool
    {
        return $user->is_active && $user->can(Permission::PatientsMerge->value);
    }

    public function export(User $user, Patient $patient): bool
    {
        return $user->is_active && $user->can(Permission::PatientsExport->value) && $this->access->canAccess($user, $patient);
    }

    /** Allergies, conditions, medications: the prescriber or whoever may edit demographics. */
    public function manageClinical(User $user, Patient $patient): bool
    {
        return $this->view($user, $patient) && ($user->can(Permission::PrescriptionsWrite->value) || $user->can(Permission::PatientsUpdate->value));
    }

    public function uploadDocument(User $user, Patient $patient): bool
    {
        return $this->manageClinical($user, $patient);
    }

    public function recordConsent(User $user, Patient $patient): bool
    {
        return $this->view($user, $patient) && ($user->can(Permission::PatientsUpdate->value) || $user->can(Permission::PatientsCreate->value));
    }
}
