<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** A super admin entered a tenant panel as one of its users (ARCHITECTURE §6.5). */
final class ImpersonationStarted
{
    use Dispatchable;

    public function __construct(
        public readonly int $tenantId,
        public readonly int $superAdminId,
        public readonly int $userId,
    ) {}
}
