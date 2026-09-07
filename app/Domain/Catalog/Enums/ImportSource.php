<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Enums;

/** catalog:import --source (SCHEMA.md Appendix A). */
enum ImportSource: string
{
    case Dgda = 'dgda';
    case Seed = 'seed';
    case Manual = 'manual';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
