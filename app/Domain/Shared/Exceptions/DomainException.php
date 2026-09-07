<?php

declare(strict_types=1);

namespace App\Domain\Shared\Exceptions;

use RuntimeException;

/**
 * Base for every business-rule failure. code() is a stable dotted '<module>.<condition>' string clients branch on;
 * status() is 422 by default and 409 for conflict-type exceptions (ARCHITECTURE §2).
 */
abstract class DomainException extends RuntimeException
{
    abstract public function code(): string;

    public function status(): int
    {
        return 422;
    }
}
