<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Enums;

/** renal_cautions.level / hepatic_cautions.level (SCHEMA.md Appendix A). */
enum CautionLevel: string
{
    case Caution = 'caution';
    case AdjustDose = 'adjust_dose';
    case Avoid = 'avoid';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
