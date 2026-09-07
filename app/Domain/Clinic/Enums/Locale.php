<?php

declare(strict_types=1);

namespace App\Domain\Clinic\Enums;

enum Locale: string
{
    case Bn = 'bn';
    case En = 'en';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
