<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

/** A catalog_versions row with the bundle's checksum already exists and --force was not given (CATALOG.md §5.1). */
final class CatalogVersionAlreadyImported extends DomainException
{
    public function __construct(public readonly string $version, public readonly string $checksum)
    {
        parent::__construct("This bundle was already imported as catalog version {$version}.");
    }

    public function code(): string
    {
        return 'catalog.already_imported';
    }

    public function status(): int
    {
        return 409;
    }
}
