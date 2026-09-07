<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Enums;

/** catalog_versions.status (SCHEMA.md Appendix A). */
enum CatalogVersionStatus: string
{
    case Draft = 'draft';
    case Released = 'released';
    case Applied = 'applied';
    case Superseded = 'superseded';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
