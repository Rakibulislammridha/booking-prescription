<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Enums;

/** catalog_reconciliation_reports.status (SCHEMA.md Appendix A). */
enum ReconciliationStatus: string
{
    case Clean = 'clean';
    case OrphansFound = 'orphans_found';
    case InactiveFound = 'inactive_found';
    case RenamedFound = 'renamed_found';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
