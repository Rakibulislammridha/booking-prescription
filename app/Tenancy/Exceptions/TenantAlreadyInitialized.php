<?php

declare(strict_types=1);

namespace App\Tenancy\Exceptions;

use App\Models\Central\Tenant;
use RuntimeException;

final class TenantAlreadyInitialized extends RuntimeException
{
    public function __construct(public readonly Tenant $active, public readonly Tenant $requested)
    {
        parent::__construct("Tenant [{$active->slug}] is already active; cannot initialise [{$requested->slug}]. Use Tenancy::run().");
    }
}
