<?php

declare(strict_types=1);

namespace App\Domain\Booking\Enums;

/** appointments.type (SCHEMA §3.3). */
enum AppointmentType: string
{
    case New = 'new';
    case Followup = 'followup';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
