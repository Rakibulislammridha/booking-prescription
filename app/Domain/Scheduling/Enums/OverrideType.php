<?php

declare(strict_types=1);

namespace App\Domain\Scheduling\Enums;

enum OverrideType: string
{
    case LateStart = 'late_start';
    case CutShort = 'cut_short';
    case Cancelled = 'cancelled';
    case CapacityChange = 'capacity_change';
    case TimeChange = 'time_change';
    case ExtraSession = 'extra_session';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
