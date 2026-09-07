<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Enums;

enum PrescriptionStatus: string
{
    case Draft = 'draft';
    case Issued = 'issued';
    case Amended = 'amended';
    case Voided = 'voided';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
