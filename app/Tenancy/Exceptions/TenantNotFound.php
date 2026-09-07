<?php

declare(strict_types=1);

namespace App\Tenancy\Exceptions;

use RuntimeException;

final class TenantNotFound extends RuntimeException
{
    public function __construct(public readonly string $identifier)
    {
        parent::__construct("Tenant [{$identifier}] was not found.");
    }
}
