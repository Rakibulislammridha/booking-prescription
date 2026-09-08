<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Features\Concerns;

use App\Domain\SaaS\Enums\PlanFeatureKey;
use App\Domain\SaaS\Services\Entitlements;
use App\Models\Central\Tenant;

/**
 * Every module toggle answers the same question — "does this tenant's plan (as negotiated) include it?" — so the
 * feature classes carry only their `PlanFeatureKey` and this trait does the work.
 *
 * The parameter is `mixed` on purpose. Pennant hands a resolver either the scope object or, once
 * `Tenant::toFeatureIdentifier()` has been applied, the `tenant:{id}` STRING — and `Decorator::definedFeaturesForScope()`
 * reflects on this signature to decide whether a feature applies to a scope at all. Typing it `?Tenant` makes
 * `Feature::for($tenant)->all()` silently return an empty map, which is exactly the shape of bug that would ship:
 * `SharedProps.features` would be `{}` and every module would look disabled on the client while `active()` said
 * otherwise on the server.
 *
 * Pennant's database driver PERSISTS a resolved value (`public.feature_flags`), which is what makes
 * `Feature::active('telemedicine')` cheap on the hot path. The stored row is a cache, never the truth:
 * `subscriptions.feature_overrides` is (SCHEMA §2.4), and `FeatureFlagCache::purge()` drops the rows whenever a
 * plan, an override or a subscription changes, so the next read re-resolves.
 *
 * A null scope (a central request, a job with no tenancy) is FALSE, never an exception: marketing pages and the
 * super console must render without a tenant.
 */
trait ResolvesFromPlan
{
    abstract public function planFeature(): PlanFeatureKey;

    public function resolve(mixed $scope): bool
    {
        $tenant = self::tenantFrom($scope);

        return $tenant !== null && app(Entitlements::class)->for($tenant)->enabled($this->planFeature());
    }

    /** `tenant:42` is what `Tenant::toFeatureIdentifier()` produces and what the stored rows are keyed by. */
    private static function tenantFrom(mixed $scope): ?Tenant
    {
        if ($scope instanceof Tenant) {
            return $scope;
        }

        if (is_string($scope) && preg_match('/^tenant:(\d+)$/', $scope, $matches) === 1) {
            return Tenant::query()->find((int) $matches[1]);
        }

        return null;
    }
}
