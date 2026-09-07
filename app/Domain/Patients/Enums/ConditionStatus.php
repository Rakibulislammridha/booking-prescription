<?php

declare(strict_types=1);

namespace App\Domain\Patients\Enums;

enum ConditionStatus: string
{
    case Active = 'active';
    case Chronic = 'chronic';
    case Resolved = 'resolved';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
