<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/** A clinic finished the sign-up wizard and its schema is live (ARCHITECTURE §5.4 'SendWelcome'). */
final class TenantOnboarded implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    public function __construct(
        public readonly int $tenantId,
        public readonly string $panelUrl,
    ) {}
}
