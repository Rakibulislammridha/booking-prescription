<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Enums;

/** `public.catalog_jobs.kind` — what the console asked Horizon to do to the shared catalog. */
enum CatalogJobKind: string
{
    case Import = 'import';
    case Reindex = 'reindex';
    case Reconcile = 'reconcile';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
