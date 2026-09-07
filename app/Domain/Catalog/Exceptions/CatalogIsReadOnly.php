<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

/**
 * Thrown by CatalogModel's creating/updating/deleting/saving hooks when no CatalogWriteContext is open (CATALOG.md §1.2).
 */
final class CatalogIsReadOnly extends DomainException
{
    public function __construct(public readonly string $model)
    {
        parent::__construct("The catalog is read-only at runtime; {$model} can only be written inside CatalogWriteContext::run().");
    }

    public function code(): string
    {
        return 'catalog.read_only';
    }

    public function status(): int
    {
        return 409;
    }
}
