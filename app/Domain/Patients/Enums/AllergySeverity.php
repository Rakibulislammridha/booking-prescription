<?php

declare(strict_types=1);

namespace App\Domain\Patients\Enums;

enum AllergySeverity: string
{
    case Mild = 'mild';
    case Moderate = 'moderate';
    case Severe = 'severe';
    case Unknown = 'unknown';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
