<?php

declare(strict_types=1);

namespace App\Domain\Reception\Enums;

/** offline_events.resolution (OFFLINE §8). record_in_closed and a cash discard need the Hospital Admin PIN. */
enum ConflictResolution: string
{
    case LinkPatient = 'link_patient';
    case FamilyMember = 'family_member';
    case Reissue = 'reissue';
    case MoveToSession = 'move_to_session';
    case RecordInClosed = 'record_in_closed';
    case Reinstate = 'reinstate';
    case RefundCash = 'refund_cash';
    case Credit = 'credit';
    case Discard = 'discard';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
