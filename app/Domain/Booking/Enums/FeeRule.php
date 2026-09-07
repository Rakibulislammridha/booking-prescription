<?php

declare(strict_types=1);

namespace App\Domain\Booking\Enums;

/** appointments.fee_rule — which rule of SCHEMA §5.10 decided `fee_paisa`. */
enum FeeRule: string
{
    case New = 'new';
    case FollowupPaid = 'followup_paid';
    case FollowupFree = 'followup_free';
    case ScheduleOverride = 'schedule_override';
    case Telemedicine = 'telemedicine';
    case Manual = 'manual';
    case Waived = 'waived';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
