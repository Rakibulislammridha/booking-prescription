<?php

declare(strict_types=1);

namespace App\Domain\Clinic\Enums;

enum TokenSlipTemplate: string
{
    case Thermal58 = 'thermal_58';
    case Thermal80 = 'thermal_80';
    case A5 = 'a5';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
