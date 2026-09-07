<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Policies;

use App\Domain\Clinic\Enums\Permission;
use App\Models\Tenant\User;
use App\Models\Tenant\Visit;

/**
 * A doctor sees / writes the visits of their own patients (visits.doctor_id) unless `prescriptions.view.any`;
 * hospital_admin holds every permission (RoleMatrix). Vitals: `prescriptions.vitals.record` (compounder + doctor).
 */
final class VisitPolicy
{
    public function view(User $user, Visit $visit): bool
    {
        return $user->is_active && ($user->can(Permission::PrescriptionsViewAny->value) || $this->ownsVisit($user, $visit) || $user->can(Permission::PrescriptionsVitalsRecord->value));
    }

    public function write(User $user, Visit $visit): bool
    {
        return $user->is_active && $user->can(Permission::PrescriptionsWrite->value) && ($this->ownsVisit($user, $visit) || $user->can(Permission::PrescriptionsViewAny->value));
    }

    public function recordVitals(User $user, Visit $visit): bool
    {
        return $user->is_active && $user->can(Permission::PrescriptionsVitalsRecord->value);
    }

    public function close(User $user, Visit $visit): bool
    {
        return $this->write($user, $visit);
    }

    private function ownsVisit(User $user, Visit $visit): bool
    {
        $doctorId = $user->doctor()->value('id');

        return $doctorId !== null && (int) $doctorId === $visit->doctor_id;
    }
}
