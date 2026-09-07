<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Enums;

/** drug_interactions.severity (SCHEMA.md Appendix A). */
enum InteractionSeverity: string
{
    case Minor = 'minor';
    case Moderate = 'moderate';
    case Major = 'major';
    case Contraindicated = 'contraindicated';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
