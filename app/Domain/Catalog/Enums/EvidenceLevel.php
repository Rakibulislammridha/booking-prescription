<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Enums;

/** drug_interactions.evidence_level (SCHEMA.md Appendix A). */
enum EvidenceLevel: string
{
    case Established = 'established';
    case Probable = 'probable';
    case Theoretical = 'theoretical';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
