<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Enums;

/** `public.catalog_jobs.status`: uploaded (a bundle waiting for a decision) → queued → running → succeeded | failed. */
enum CatalogJobStatus: string
{
    case Uploaded = 'uploaded';
    case Queued = 'queued';
    case Running = 'running';
    case Succeeded = 'succeeded';
    case Failed = 'failed';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
