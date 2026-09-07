<?php

declare(strict_types=1);

namespace App\Domain\Patients\Enums;

enum PatientSource: string
{
    case Online = 'online';
    case Counter = 'counter';
    case Kiosk = 'kiosk';
    case Import = 'import';
    case Walkin = 'walkin';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
