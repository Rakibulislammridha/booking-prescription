<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** A TXT proof checked out; TenantResolver will now route this hostname to the tenant. */
final class CustomDomainVerified implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $tenantId,
        public readonly int $domainId,
        public readonly string $host,
    ) {}
}
