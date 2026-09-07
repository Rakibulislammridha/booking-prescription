<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Enums;

/** max_daily_doses.population (SCHEMA.md Appendix A). */
enum DosePopulation: string
{
    case Adult = 'adult';
    case Pediatric = 'pediatric';
    case Elderly = 'elderly';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
