<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Enums;

enum BackupType: string
{
    case Daily = 'daily';
    case Manual = 'manual';
    case Export = 'export';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
