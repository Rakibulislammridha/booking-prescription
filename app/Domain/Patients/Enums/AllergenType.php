<?php

declare(strict_types=1);

namespace App\Domain\Patients\Enums;

enum AllergenType: string
{
    case Generic = 'generic';
    case AllergyClass = 'allergy_class';
    case Food = 'food';
    case Environmental = 'environmental';
    case Other = 'other';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
