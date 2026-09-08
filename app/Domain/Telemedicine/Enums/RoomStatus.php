<?php

declare(strict_types=1);

namespace App\Domain\Telemedicine\Enums;

/** `telemedicine_rooms.status` (SCHEMA §3.8, Appendix A). */
enum RoomStatus: string
{
    case Scheduled = 'scheduled';
    case Open = 'open';
    case Ended = 'ended';
    case Cancelled = 'cancelled';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }

    /** A room nobody may join any more: the consultation is over, or it never happened. */
    public function isTerminal(): bool
    {
        return $this === self::Ended || $this === self::Cancelled;
    }

    public function isJoinable(): bool
    {
        return $this === self::Scheduled || $this === self::Open;
    }
}
