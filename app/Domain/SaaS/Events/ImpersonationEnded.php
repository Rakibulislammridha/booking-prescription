<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Events;

use Illuminate\Foundation\Events\Dispatchable;

/** The impersonated session was left — deliberately, not by timeout. */
final class ImpersonationEnded
{
    use Dispatchable;

    public function __construct(
        public readonly int $tenantId,
        public readonly int $superAdminId,
        public readonly int $userId,
    ) {}
}
