<?php

declare(strict_types=1);

namespace App\Domain\Billing\Enums;

/** cash_shifts.status (SCHEMA §3.5). */
enum CashShiftStatus: string
{
    case Open = 'open';
    case Closed = 'closed';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
