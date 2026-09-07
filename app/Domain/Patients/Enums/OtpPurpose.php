<?php

declare(strict_types=1);

namespace App\Domain\Patients\Enums;

enum OtpPurpose: string
{
    case Login = 'login';
    case Booking = 'booking';
    case VerifyMobile = 'verify_mobile';
    case Consent = 'consent';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
