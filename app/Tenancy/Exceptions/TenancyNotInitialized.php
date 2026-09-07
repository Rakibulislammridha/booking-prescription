<?php

declare(strict_types=1);

namespace App\Tenancy\Exceptions;

use RuntimeException;

final class TenancyNotInitialized extends RuntimeException
{
    public function __construct(public readonly string $modelClass)
    {
        parent::__construct("Tenancy is not initialised; [{$modelClass}] is a tenant model and cannot be queried centrally.");
    }
}
