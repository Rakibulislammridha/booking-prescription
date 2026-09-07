<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Enums;

/** pregnancy_categories.category (SCHEMA.md Appendix A). */
enum PregnancyCategory: string
{
    case A = 'A';
    case B = 'B';
    case C = 'C';
    case D = 'D';
    case X = 'X';
    case N = 'N';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
