<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

/** Another job of the same kind is queued or running, or the bundle to run is gone (CatalogJobs). */
final class CatalogJobBusy extends DomainException
{
    public function __construct(string $reason)
    {
        parent::__construct($reason);
    }

    public function code(): string
    {
        return 'catalog.job_busy';
    }

    public function status(): int
    {
        return 409;
    }
}
