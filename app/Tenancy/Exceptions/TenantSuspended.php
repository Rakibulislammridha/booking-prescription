<?php

declare(strict_types=1);

namespace App\Tenancy\Exceptions;

use App\Models\Central\Tenant;
use RuntimeException;

final class TenantSuspended extends RuntimeException
{
    public function __construct(public readonly Tenant $tenant)
    {
        parent::__construct("Tenant [{$tenant->slug}] is suspended.");
    }
}
