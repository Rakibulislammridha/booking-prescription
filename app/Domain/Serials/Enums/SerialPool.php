<?php

declare(strict_types=1);

namespace App\Domain\Serials\Enums;

enum SerialPool: string
{
    case Online = 'online';
    case Counter = 'counter';
    case Buffer = 'buffer';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }

    /**
     * Lock order everywhere: pools by name ascending — buffer, counter, online (SERIAL_ENGINE §4.2).
     *
     * @return array<int, self>
     */
    public static function lockOrder(): array
    {
        return [self::Buffer, self::Counter, self::Online];
    }
}
