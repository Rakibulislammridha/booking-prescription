<?php

declare(strict_types=1);

namespace App\Domain\Serials\Enums;

enum SerialSource: string
{
    case Online = 'online';
    case Counter = 'counter';
    case Walkin = 'walkin';
    case Kiosk = 'kiosk';
    case Followup = 'followup';
    case Offline = 'offline';

    /**
     * Which pool a channel draws from (SERIAL_ENGINE §3.2). `followup` is counter when staff act, online when the
     * patient acts; `offline` draws from the device's block, which is carved from the counter pool.
     */
    public function defaultPool(bool $staffActor = true): SerialPool
    {
        return match ($this) {
            self::Online, self::Kiosk => SerialPool::Online,
            self::Counter, self::Offline => SerialPool::Counter,
            self::Walkin => SerialPool::Buffer,
            self::Followup => $staffActor ? SerialPool::Counter : SerialPool::Online,
        };
    }

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
