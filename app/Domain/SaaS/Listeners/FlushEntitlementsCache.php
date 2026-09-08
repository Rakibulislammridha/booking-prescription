<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Listeners;

use App\Domain\SaaS\Gateways\SubscriptionGatewayManager;
use App\Domain\SaaS\Services\Entitlements;

/**
 * Entering or leaving a tenant invalidates every per-tenant memo this module holds. `Entitlements` is `scoped`,
 * so the container drops it between Octane operations — but a single request that calls `Tenancy::run()` for
 * another clinic (the super console does exactly that) never leaves the container, and answering with the
 * previous tenant's plan would be a cross-tenant leak with a plausible-looking value.
 */
final class FlushEntitlementsCache
{
    public function __construct(
        private readonly Entitlements $entitlements,
        private readonly SubscriptionGatewayManager $gateways,
    ) {}

    public function handle(object $event): void
    {
        $this->entitlements->forget();
        $this->gateways->forget();
    }
}
