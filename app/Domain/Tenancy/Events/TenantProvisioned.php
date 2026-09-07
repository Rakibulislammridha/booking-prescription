<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Events;

use App\Models\Central\Tenant;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

final class TenantProvisioned implements ShouldDispatchAfterCommit
{
    public function __construct(public readonly Tenant $tenant) {}
}
