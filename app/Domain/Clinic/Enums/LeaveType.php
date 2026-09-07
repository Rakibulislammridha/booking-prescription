<?php

declare(strict_types=1);

namespace App\Domain\Clinic\Enums;

enum LeaveType: string
{
    case Planned = 'planned';
    case Emergency = 'emergency';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
