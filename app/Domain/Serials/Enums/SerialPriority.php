<?php

declare(strict_types=1);

namespace App\Domain\Serials\Enums;

enum SerialPriority: string
{
    case Normal = 'normal';
    case Elderly = 'elderly';
    case Emergency = 'emergency';
    case Vip = 'vip';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
