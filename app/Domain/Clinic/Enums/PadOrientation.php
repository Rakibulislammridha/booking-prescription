<?php

declare(strict_types=1);

namespace App\Domain\Clinic\Enums;

enum PadOrientation: string
{
    case Portrait = 'portrait';
    case Landscape = 'landscape';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
