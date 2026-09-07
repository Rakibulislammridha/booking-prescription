<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Import;

use App\Domain\Catalog\Data\ImportRow;

/** Source-specific record → ImportRow(s) (CATALOG.md §5.2). */
interface RowMapper
{
    /**
     * @param  array<string, string>  $record  normalised CSV row
     * @return iterable<ImportRow>
     */
    public function map(array $record, int $line): iterable;
}
