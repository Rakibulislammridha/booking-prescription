<?php

declare(strict_types=1);

namespace App\Domain\Patients\Enums;

enum OtpChannel: string
{
    case Sms = 'sms';
    case Ivr = 'ivr';
    case Whatsapp = 'whatsapp';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
