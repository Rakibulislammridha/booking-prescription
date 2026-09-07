<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Enums;

/** prescriptions.language and doctor_pad_settings.default_language. */
enum PrescriptionLanguage: string
{
    case Bn = 'bn';
    case En = 'en';
    case Both = 'both';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
