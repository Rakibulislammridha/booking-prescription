<?php

declare(strict_types=1);

namespace App\Domain\Reception\Enums;

enum DeviceStatus: string
{
    case Active = 'active';
    case Revoked = 'revoked';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
