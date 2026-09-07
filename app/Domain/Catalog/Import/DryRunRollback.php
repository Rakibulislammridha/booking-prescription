<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Import;

use App\Domain\Catalog\Data\ImportReport;
use RuntimeException;

/** Thrown inside the write transaction to roll a --dry-run back while carrying the would-be report out. */
final class DryRunRollback extends RuntimeException
{
    public function __construct(public readonly ImportReport $report)
    {
        parent::__construct('dry run');
    }
}
