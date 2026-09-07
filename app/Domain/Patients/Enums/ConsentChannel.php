<?php

declare(strict_types=1);

namespace App\Domain\Patients\Enums;

enum ConsentChannel: string
{
    case Counter = 'counter';
    case Online = 'online';
    case Kiosk = 'kiosk';
    case App = 'app';
    case Phone = 'phone';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
