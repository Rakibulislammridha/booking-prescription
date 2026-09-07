<?php

declare(strict_types=1);

namespace App\Domain\Clinic\Enums;

enum PadPaperSize: string
{
    case A4 = 'A4';
    case A5 = 'A5';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
