<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Listeners;

use App\Domain\Tenancy\Events\TenantProvisioned;
use Laravel\Pennant\Feature;

/**
 * Resolve and persist every module toggle for a brand-new clinic, once, at provisioning.
 *
 * Pennant's database driver resolves lazily and stores what it resolved, so WITHOUT this the first request a
 * clinic ever makes pays for eight resolutions and a batched insert — and `HandleInertiaRequests` asks for all of
 * them on every page, which is the hot path for every panel screen in the product. Priming here moves that cost
 * to provisioning, where it belongs and where nobody is waiting.
 *
 * The rows are a cache, not the truth (`subscriptions.feature_overrides` is), and `FeatureFlagCache` drops them
 * whenever an entitlement moves — so priming can never freeze a stale answer.
 */
final class PrimeFeatureFlags
{
    public function handle(TenantProvisioned $event): void
    {
        Feature::for($event->tenant)->all();
    }
}
