<?php

declare(strict_types=1);

namespace App\Domain\Clinic\Exceptions;

use App\Domain\Shared\Exceptions\DomainException;

/**
 * A staff account from another clinic reached the assignment. The tenant scope and route binding already make this
 * unreachable over HTTP; it is asserted anyway because this pivot is a permission boundary, and a boundary that is
 * only ever checked one layer up is checked nowhere.
 */
final class CompounderFromAnotherTenant extends DomainException
{
    public function code(): string
    {
        return 'clinic.compounder.foreign_tenant';
    }

    public function status(): int
    {
        return 422;
    }
}
