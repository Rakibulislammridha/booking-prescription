<?php

declare(strict_types=1);

namespace App\Domain\Reception\Enums;

/** offline_events.type (OFFLINE §6.1). The reserved values stay in the CHECK but are never sent by the v1 client. */
enum OfflineEventType: string
{
    case RegisterPatient = 'register_patient';
    case IssueSerial = 'issue_serial';
    case CheckIn = 'check_in';
    case CollectCash = 'collect_cash';
    case PrintToken = 'print_token';
    case VoidLocal = 'void_local';
    case MarkArrived = 'mark_arrived';
    case CancelSerial = 'cancel_serial';
    case AssignPatient = 'assign_patient';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }

    /** @return array<int, string> the types the v1 client may send (OFFLINE §6.1) */
    public static function supported(): array
    {
        return [self::RegisterPatient->value, self::IssueSerial->value, self::CheckIn->value, self::CollectCash->value, self::PrintToken->value, self::VoidLocal->value];
    }
}
