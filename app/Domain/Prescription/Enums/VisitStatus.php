<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Enums;

enum VisitStatus: string
{
    case Open = 'open';
    case Closed = 'closed';
    case Cancelled = 'cancelled';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
