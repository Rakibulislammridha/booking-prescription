<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

/** CatalogWriteContext::run() called outside the console and without a super-admin session (CATALOG.md §1.3). */
final class CatalogWriteNotAllowed extends DomainException
{
    public function __construct()
    {
        parent::__construct('Catalog writes are limited to console commands and super admins.');
    }

    public function code(): string
    {
        return 'catalog.write_not_allowed';
    }

    public function status(): int
    {
        return 403;
    }
}
