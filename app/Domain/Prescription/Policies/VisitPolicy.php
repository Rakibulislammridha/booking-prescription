<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Policies;

use App\Domain\Clinic\Enums\Permission;
use App\Domain\Clinic\Services\DoctorScope;
use App\Models\Tenant\User;
use App\Models\Tenant\Visit;

/**
 * A doctor sees / writes the visits of their own patients (visits.doctor_id) unless `prescriptions.view.any`;
 * hospital_admin holds every permission (RoleMatrix). Vitals: `prescriptions.vitals.record` (compounder + doctor).
 *
 * `prescriptions.vitals.record` is a clinic-wide permission, so on its own it opened every visit and every vitals
 * write in the building. DoctorScope is the second half of the rule for anyone it restricts: the visit names its
 * doctor (`visits.doctor_id`), and a compounder only reads and writes the visits of the doctors they work for.
 */
final class VisitPolicy
{
    public function __construct(private readonly DoctorScope $scope) {}

    public function view(User $user, Visit $visit): bool
    {
        return $user->is_active
            && $this->scope->allows($user, $visit->doctor_id)
            && ($user->can(Permission::PrescriptionsViewAny->value) || $this->ownsVisit($user, $visit) || $user->can(Permission::PrescriptionsVitalsRecord->value));
    }

    public function write(User $user, Visit $visit): bool
    {
        return $user->is_active && $user->can(Permission::PrescriptionsWrite->value) && ($this->ownsVisit($user, $visit) || $user->can(Permission::PrescriptionsViewAny->value));
    }

    /**
     * The vitals write itself (POST /panel/visits/{visit}/vitals, PATCH /panel/vitals/{vital}, and the desk's own
     * entry screen). The permission says "this role takes readings"; DoctorScope says whose patients they take them
     * from — without it a compounder writes into any doctor's encounter, which is the one thing the feature forbids.
     */
    public function recordVitals(User $user, Visit $visit): bool
    {
        return $user->is_active
            && $user->can(Permission::PrescriptionsVitalsRecord->value)
            && $this->scope->allows($user, $visit->doctor_id);
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
