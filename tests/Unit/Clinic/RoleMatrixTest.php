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

        foreach ([Role::Doctor, Role::Receptionist, Role::Accountant] as $role) {
            $this->assertNotEmpty(RoleMatrix::permissionsFor($role));
            $this->assertLessThan(count(Permission::cases()), count(RoleMatrix::permissionsFor($role)));
        }
    }

    public function test_split_adjust_is_doctor_only_and_permission_names_follow_the_grammar(): void
    {
        $this->assertContains(Permission::SerialsSplitAdjust, RoleMatrix::permissionsFor(Role::Doctor));
        $this->assertNotContains(Permission::SerialsSplitAdjust, RoleMatrix::permissionsFor(Role::Receptionist));
        $this->assertNotContains(Permission::SerialsSplitAdjust, RoleMatrix::permissionsFor(Role::Accountant));

        foreach (Permission::cases() as $permission) {
            $this->assertMatchesRegularExpression('/^[a-z]+(\.[a-z-]+){1,2}$/', $permission->value);
        }

        $this->assertSame(['hospital_admin', 'doctor', 'receptionist', 'accountant'], Role::values());
    }
}
