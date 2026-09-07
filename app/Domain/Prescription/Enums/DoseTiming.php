<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Enums;

/** prescription_items.timing (PRESCRIPTION.md §2.6). */
enum DoseTiming: string
{
    case Before = 'before';
    case After = 'after';
    case With = 'with';
    case Any = 'any';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
