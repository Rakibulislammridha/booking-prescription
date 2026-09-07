<?php

declare(strict_types=1);

namespace App\Domain\Clinic\Enums;

enum Role: string
{
    case HospitalAdmin = 'hospital_admin';
    case Doctor = 'doctor';
    case Receptionist = 'receptionist';
    case Accountant = 'accountant';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
