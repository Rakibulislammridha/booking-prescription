<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Enums;

/** pregnancy_categories.lactation (SCHEMA.md Appendix A). */
enum LactationRisk: string
{
    case Safe = 'safe';
    case Caution = 'caution';
    case Avoid = 'avoid';
    case Unknown = 'unknown';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
