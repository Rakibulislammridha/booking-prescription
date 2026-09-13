<?php

declare(strict_types=1);

namespace Tests\Unit\Clinic;

use App\Domain\Clinic\Enums\Permission;
use App\Domain\Clinic\Enums\Role;
use App\Domain\Clinic\Support\RoleMatrix;
use PHPUnit\Framework\TestCase;

final class RoleMatrixTest extends TestCase
{
    public function test_hospital_admin_has_everything_and_others_are_subsets(): void
    {
        $this->assertSame(Permission::cases(), RoleMatrix::permissionsFor(Role::HospitalAdmin));

        foreach ([Role::Doctor, Role::Receptionist, Role::Accountant, Role::Compounder] as $role) {
            $this->assertNotEmpty(RoleMatrix::permissionsFor($role));
            $this->assertLessThan(count(Permission::cases()), count(RoleMatrix::permissionsFor($role)));
        }
    }

    /**
     * "He can't be able to edit the serial number" is a permission the compounder does NOT hold, not a hidden
     * button: every serial-number permission is asserted absent here, and check-in is asserted present for the
     * three roles that work an arrival desk.
     */
    public function test_the_compounder_checks_in_and_holds_nothing_that_moves_a_serial(): void
    {
        $compounder = RoleMatrix::permissionsFor(Role::Compounder);

        $this->assertSame([
            Permission::SerialsCheckIn,
            Permission::PrescriptionsVitalsRecord,
            Permission::BillingPaymentsCollect,
            Permission::BillingInvoicesView,
        ], $compounder);

        foreach ([
            Permission::SerialsIssueCounter, Permission::SerialsReorder, Permission::SerialsTransfer,
            Permission::SerialsCancel, Permission::SerialsSplitAdjust, Permission::SerialsCapacityExtend,
            Permission::QueueCallNext, Permission::PrescriptionsWrite, Permission::PrescriptionsViewAny,
            Permission::PatientsView, Permission::PatientsCreate, Permission::PatientsUpdate,
            Permission::ReportsView, Permission::BillingRefundsIssue, Permission::ClinicDoctorsManage,
        ] as $forbidden) {
            $this->assertNotContains($forbidden, $compounder, $forbidden->value.' must stay off the compounder');
        }

        foreach ([Role::Receptionist, Role::Doctor, Role::Compounder] as $role) {
            $this->assertContains(Permission::SerialsCheckIn, RoleMatrix::permissionsFor($role));
        }

        $this->assertNotContains(Permission::SerialsCheckIn, RoleMatrix::permissionsFor(Role::Accountant));
    }

    public function test_split_adjust_is_doctor_only_and_permission_names_follow_the_grammar(): void
    {
        $this->assertContains(Permission::SerialsSplitAdjust, RoleMatrix::permissionsFor(Role::Doctor));
        $this->assertNotContains(Permission::SerialsSplitAdjust, RoleMatrix::permissionsFor(Role::Receptionist));
        $this->assertNotContains(Permission::SerialsSplitAdjust, RoleMatrix::permissionsFor(Role::Accountant));

        foreach (Permission::cases() as $permission) {
            $this->assertMatchesRegularExpression('/^[a-z]+(\.[a-z-]+){1,2}$/', $permission->value);
        }

        $this->assertSame(['hospital_admin', 'doctor', 'receptionist', 'accountant', 'compounder'], Role::values());
    }
}
