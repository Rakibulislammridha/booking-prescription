<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

/** The import path holds no readable data files, or a file is malformed beyond row-level issues. */
final class ImportBundleInvalid extends DomainException
{
    public function __construct(string $reason)
    {
        parent::__construct($reason);
    }

    public function code(): string
    {
        return 'catalog.import_bundle_invalid';
    }
}
