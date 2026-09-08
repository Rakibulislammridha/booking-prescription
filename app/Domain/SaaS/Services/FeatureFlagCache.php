<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Services;

use App\Domain\SaaS\Enums\PlanFeatureKey;
use App\Models\Central\Tenant;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;

/**
 * `public.feature_flags` holds Pennant's RESOLVED values, which makes it a cache of the plan — and a cache has to
 * be dropped when the thing it caches moves. Every write that can change what a tenant is entitled to (plan
 * change, `feature_overrides` edit, subscription status transition, a plan's own `plan_features` being edited)
 * ends by purging here, so the very next request re-resolves from `Entitlements`.
 *
 * The purge is one DELETE over `(name, scope)`, not a per-feature Pennant call, because editing a plan can touch
 * thousands of tenants and `Feature::for(...)->forget()` would be one round trip each.
 */
final class FeatureFlagCache
{
    public function __construct(private readonly Entitlements $entitlements) {}

    public function forTenant(Tenant $tenant): void
    {
        $this->purgeScopes([$tenant->toFeatureIdentifier('database')]);
        $this->entitlements->forget($tenant->id);
        Feature::flushCache();
    }

    /** @param  array<int, int>  $tenantIds */
    public function forTenantIds(array $tenantIds): void
    {
        if ($tenantIds === []) {
            return;
        }

        $this->purgeScopes(array_map(fn (int $id) => "tenant:{$id}", $tenantIds));

        foreach ($tenantIds as $id) {
            $this->entitlements->forget($id);
        }

        Feature::flushCache();
    }

    /** Every tenant with a live subscription to this plan — used when the plan's own feature rows change. */
    public function forPlan(int $planId): void
    {
        /** @var array<int, int> $ids */
        $ids = DB::connection('pgsql')->table('public.subscriptions')
            ->where('plan_id', $planId)
            ->pluck('tenant_id')
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        $this->forTenantIds($ids);
    }

    /** @param  array<int, string>  $scopes */
    private function purgeScopes(array $scopes): void
    {
        $names = array_values(array_filter(array_map(fn (PlanFeatureKey $k) => $k->featureName(), PlanFeatureKey::toggles())));

        DB::connection('pgsql')->table('public.feature_flags')
            ->whereIn('scope', $scopes)
            ->whereIn('name', $names)
            ->delete();
    }
}
