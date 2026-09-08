<?php

declare(strict_types=1);

namespace App\Domain\Telemedicine\Enums;

/** `telemedicine_sessions.end_reason` (SCHEMA §3.8, Appendix A). */
enum SessionEndReason: string
{
    case Completed = 'completed';
    case Dropped = 'dropped';
    case NoShow = 'no_show';
    case Cancelled = 'cancelled';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }

    /** Only a completed call drives CompleteConsultation; a dropped one leaves the serial in consultation to rejoin. */
    public function completesTheSerial(): bool
    {
        return $this === self::Completed;
    }
}
