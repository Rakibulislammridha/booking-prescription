<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Enums;

enum InvestigationCategory: string
{
    case Lab = 'lab';
    case Imaging = 'imaging';
    case Procedure = 'procedure';
    case Other = 'other';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
