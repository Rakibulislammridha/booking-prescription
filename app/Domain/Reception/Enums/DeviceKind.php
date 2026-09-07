<?php

declare(strict_types=1);

namespace App\Domain\Reception\Enums;

/** reception_devices.kind: a desk tablet leases blocks; a display box only subscribes to the display channel. */
enum DeviceKind: string
{
    case Reception = 'reception';
    case Display = 'display';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
