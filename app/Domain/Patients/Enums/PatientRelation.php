<?php

declare(strict_types=1);

namespace App\Domain\Patients\Enums;

enum PatientRelation: string
{
    case Spouse = 'spouse';
    case Child = 'child';
    case Parent = 'parent';
    case Sibling = 'sibling';
    case GuardianOf = 'guardian_of';
    case Other = 'other';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
