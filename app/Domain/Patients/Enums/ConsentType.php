<?php

declare(strict_types=1);

namespace App\Domain\Patients\Enums;

enum ConsentType: string
{
    case DataProcessing = 'data_processing';
    case DataSharing = 'data_sharing';
    case Sms = 'sms';
    case Whatsapp = 'whatsapp';
    case Telemedicine = 'telemedicine';
    case Research = 'research';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
