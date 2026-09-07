<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Enums;

enum PlanFeatureKey: string
{
    case Branches = 'branches';
    case Doctors = 'doctors';
    case AppointmentsMonthly = 'appointments_monthly';
    case SmsCreditsMonthly = 'sms_credits_monthly';
    case StorageBytes = 'storage_bytes';
    case Telemedicine = 'telemedicine';
    case AiAssist = 'ai_assist';
    case Whatsapp = 'whatsapp';
    case Ivr = 'ivr';
    case CustomDomain = 'custom_domain';
    case ReportsExport = 'reports_export';
    case WaitingRoomDisplay = 'waiting_room_display';
    case HandwritingMode = 'handwriting_mode';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
