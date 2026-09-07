<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Enums;

enum VisitType: string
{
    case Opd = 'opd';
    case Followup = 'followup';
    case Telemedicine = 'telemedicine';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
