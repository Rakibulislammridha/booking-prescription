<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Enums;

enum UsageMetric: string
{
    case Appointments = 'appointments';
    case SmsCredits = 'sms_credits';
    case WhatsappMessages = 'whatsapp_messages';
    case StorageBytes = 'storage_bytes';
    case Doctors = 'doctors';
    case Branches = 'branches';
    case Prescriptions = 'prescriptions';
    case TelemedicineMinutes = 'telemedicine_minutes';
    case AiRequests = 'ai_requests';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
