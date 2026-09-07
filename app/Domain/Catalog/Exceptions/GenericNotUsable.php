<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

/** The generic a custom brand points at does not exist or is discontinued (BRIEF §3.4: a molecule link is mandatory). */
final class GenericNotUsable extends DomainException
{
    public function __construct(public readonly int $genericId)
    {
        parent::__construct("Generic #{$genericId} does not exist in the catalog or is discontinued.");
    }

    public function code(): string
    {
        return 'catalog.generic_not_usable';
    }
}
