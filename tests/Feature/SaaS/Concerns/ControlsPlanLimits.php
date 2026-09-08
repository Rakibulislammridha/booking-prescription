<?php

declare(strict_types=1);

namespace Tests\Feature\SaaS\Concerns;

use App\Domain\SaaS\Enums\PlanFeatureKey;
use App\Domain\SaaS\Enums\UsageMetric;
use App\Domain\SaaS\Services\Entitlements;
use App\Domain\SaaS\Services\FeatureFlagCache;
use App\Domain\SaaS\Services\UsageMeter;
use App\Models\Central\Subscription;
use App\Models\Central\Tenant;

/**
 * The two shared fixture tenants are provisioned with UNLIMITED overrides (see
 * `App\Domain\SaaS\Listeners\RelaxLimitsForTestFixtures` — 1300 tests written before plan limits existed create
 * a second branch or a fourth doctor without asking anyone's permission).
 *
 * A limit test therefore has to say what the limit IS, which is exactly what a limit test should do anyway: the
 * boundary being asserted is visible in the test rather than inherited from a seeder three files away.
 */
trait ControlsPlanLimits
{
    protected function setLimit(Tenant $tenant, PlanFeatureKey $key, ?int $value, bool $enabled = true): void
    {
        $subscription = Subscription::query()->findOrFail($tenant->current_subscription_id);
        /** @var array<string, mixed> $overrides */
        $overrides = (array) $subscription->feature_overrides;
        $overrides[$key->value] = ['limit_value' => $value, 'enabled' => $enabled];

        $subscription->forceFill(['feature_overrides' => $overrides])->save();
        $this->flushEntitlements($tenant);
    }

    protected function setToggle(Tenant $tenant, PlanFeatureKey $key, bool $enabled): void
    {
        $subscription = Subscription::query()->findOrFail($tenant->current_subscription_id);
        /** @var array<string, mixed> $overrides */
        $overrides = (array) $subscription->feature_overrides;
        $overrides[$key->value] = ['enabled' => $enabled];

        $subscription->forceFill(['feature_overrides' => $overrides])->save();
        $this->flushEntitlements($tenant);
    }

    protected function flushEntitlements(Tenant $tenant): void
    {
        app(FeatureFlagCache::class)->forTenant($tenant);
        app(Entitlements::class)->forget();
    }

    protected function usage(Tenant $tenant, UsageMetric $metric): int
    {
        return app(UsageMeter::class)->value($tenant, $metric);
    }

    protected function setUsage(Tenant $tenant, UsageMetric $metric, int $value): void
    {
        app(UsageMeter::class)->set($tenant, $metric, $value);
    }
}
