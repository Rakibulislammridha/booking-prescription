<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Events;

use App\Models\Central\Tenant;

final class TenancyEnded
{
    public function __construct(public readonly Tenant $tenant) {}
}
