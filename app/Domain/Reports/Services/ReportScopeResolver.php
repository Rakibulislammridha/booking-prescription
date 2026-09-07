<?php

declare(strict_types=1);

namespace App\Domain\Reports\Services;

use App\Domain\Clinic\Enums\Permission;
use App\Domain\Clinic\Enums\Role;
use App\Domain\Reports\Data\ReportScope;
use App\Models\Tenant\User;

/**
 * Turns a staff user into the scope his reports run under (BRIEF §5.L + §5.N). Resolution happens once, in the
 * controller, and the resulting scope OVERWRITES the doctor filter — it never merely validates it — so a
 * doctor who edits `?doctor_id=` in the address bar still gets his own numbers.
 *
 *   hospital_admin  every permission → the whole clinic, money and clinical
 *   accountant      reports.financial.view + reports.all-doctors.view → money for every doctor, no clinical detail
 *   doctor          reports.clinical.view, no all-doctors → his own clinical numbers, no clinic revenue
 *   receptionist    no reports.view at all → the section is not in the nav and every route 403s
 *
 * A user who holds `reports.view` but is neither a doctor nor allowed all doctors would see nothing meaningful,
 * so `doctorId` stays null and every query filters on `doctor_id = null`, i.e. returns empty — deny by
 * construction rather than by an accidental unfiltered query.
 */
final class ReportScopeResolver
{
    public function for(?User $user): ReportScope
    {
        if ($user === null || ! $user->is_active || ! $user->can(Permission::ReportsView->value)) {
            return ReportScope::none();
        }

        $allDoctors = $user->can(Permission::ReportsAllDoctorsView->value);

        return new ReportScope(
            financial: $user->can(Permission::ReportsFinancialView->value),
            clinical: $user->can(Permission::ReportsClinicalView->value),
            allDoctors: $allDoctors,
            canExport: $user->can(Permission::ReportsExport->value),
            doctorId: $allDoctors ? null : $this->ownDoctorId($user),
        );
    }

    /** The doctor row this staff login IS, when there is one (`users.id` ← `doctors.user_id`). */
    private function ownDoctorId(?User $user): ?int
    {
        if ($user === null || ! $user->hasRole(Role::Doctor->value)) {
            return null;
        }

        return $user->doctor?->id;
    }
}
