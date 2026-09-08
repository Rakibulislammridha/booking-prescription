<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Listeners;

use App\Domain\SaaS\Enums\PlanFeatureKey;
use App\Domain\SaaS\Services\SubscriptionLifecycle;
use App\Domain\Tenancy\Events\TenantProvisioned;

/**
 * Testing environment only, and only for the two SHARED fixture tenants (`tenant_test_a` / `tenant_test_b`, ids
 * 9001 / 9002 — `Tests\Concerns\WithTenants`).
 *
 * Those two schemas are provisioned once per process on the `starter` plan and are then used by every feature
 * test in the repository — suites written long before plan limits existed, which routinely create a second
 * branch or a fourth doctor because that is what their scenario needs. Enforcing Starter's caps on them would
 * turn a correct limit implementation into ~1300 unrelated failures.
 *
 * So the fixtures are provisioned with UNLIMITED numeric overrides, and every test that is actually about limits
 * sets the cap it wants on the tenant under test (`feature_overrides`, or its own plan) — which a limit test has
 * to do anyway to control the boundary it is asserting. Module toggles are deliberately NOT relaxed: a feature
 * that leaks without a plan is a bug the suite should catch.
 *
 * Production is untouched: the listener returns immediately outside `testing`.
 */
final class RelaxLimitsForTestFixtures
{
    /** @var array<int, int> */
    private const FIXTURE_TENANT_IDS = [9001, 9002];

    public function __construct(private readonly SubscriptionLifecycle $lifecycle) {}

    public function handle(TenantProvisioned $event): void
    {
        if (! app()->environment('testing') || ! in_array($event->tenant->id, self::FIXTURE_TENANT_IDS, true)) {
            return;
        }

        $subscription = $this->lifecycle->current($event->tenant);

        if ($subscription === null) {
            return;
        }

        $overrides = (array) $subscription->feature_overrides;

        foreach (PlanFeatureKey::limits() as $key) {
            $overrides[$key->value] = ['limit_value' => null, 'enabled' => true];
        }

        $subscription->forceFill(['feature_overrides' => $overrides])->save();
    }
}
