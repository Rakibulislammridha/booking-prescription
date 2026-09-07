<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Enums;

/** catalog_import_issues.kind (SCHEMA.md Appendix A). */
enum CatalogImportIssueKind: string
{
    case UnknownGeneric = 'unknown_generic';
    case UnparsableStrength = 'unparsable_strength';
    case UnknownForm = 'unknown_form';
    case DuplicateBrand = 'duplicate_brand';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
