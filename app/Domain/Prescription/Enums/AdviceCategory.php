<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Enums;

/** advice_snippets.category — `finding` and `complaint` power the O/E and C/C quick-picks (PRESCRIPTION.md §4.1, §4.3). */
enum AdviceCategory: string
{
    case Diet = 'diet';
    case Lifestyle = 'lifestyle';
    case Warning = 'warning';
    case Followup = 'followup';
    case General = 'general';
    case Finding = 'finding';
    case Complaint = 'complaint';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
