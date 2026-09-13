<?php

declare(strict_types=1);

namespace App\Domain\Clinic\Enums;

/**
 * Staff roles. New cases are APPENDED: RolesAndPermissionsSeeder inserts roles in enum order, and
 * ClinicActionsTest asserts the seeded rows come back in that same insertion order.
 */
enum Role: string
{
    case HospitalAdmin = 'hospital_admin';
    case Doctor = 'doctor';
    case Receptionist = 'receptionist';
    case Accountant = 'accountant';
    // A compounder works one or more named doctors' desks (BRIEF: "a doctor can assign a compounder").
    // The role carries no doctor of its own: which doctors it may act for is the `doctor_compounder`
    // assignment, read through App\Domain\Clinic\Services\DoctorScope.
    case Compounder = 'compounder';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
