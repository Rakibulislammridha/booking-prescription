<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Queries;

use App\Domain\SaaS\Enums\PlanFeatureKey;
use App\Domain\SaaS\Enums\SubscriptionStatus;
use App\Domain\SaaS\Enums\UsageMetric;
use Illuminate\Support\Facades\DB;

/**
 * The numeric cap of every tenant for one metric, in THREE queries for the whole platform — the usage board sorts
 * every clinic by "% of limit" and the dashboard counts clinics near or over a cap, and neither can afford
 * `Entitlements::for()` per tenant (two queries each) across the platform.
 *
 * It resolves exactly what `App\Domain\SaaS\Services\Entitlements` resolves, for the numeric keys only: every
 * LIVE subscription's `plan_features` row with `subscriptions.feature_overrides` on top, union across add-ons
 * (null = unlimited beats every number, otherwise the larger cap), a disabled row meaning cap 0, a key no live
 * plan mentions meaning unlimited, and no live subscription at all meaning unlimited (`PlanEntitlements::none()`).
 * `SuperUsageBoardTest` pins this against `PlanLimits::limit()` so the two cannot drift.
 */
final class TenantLimitsSnapshot
{
    /** @var array<int, string> */
    private const LIVE = [SubscriptionStatus::Trialing->value, SubscriptionStatus::Active->value, SubscriptionStatus::PastDue->value];

    /**
     * @param  array<int, int>|null  $tenantIds  restrict to these tenants; null is the whole platform
     * @return array<int, int|null> tenant id => cap (null = unlimited); a tenant absent from the map is unlimited
     */
    public function forMetric(UsageMetric $metric, ?array $tenantIds = null): array
    {
        $key = $metric->limitKey();

        if ($key === null) {
            return [];
        }

        $all = $this->forKeys([$key], $tenantIds);
        $out = [];

        foreach ($all as $tenantId => $limits) {
            $out[$tenantId] = $limits[$key->value] ?? null;
        }

        return $out;
    }

    /**
     * @param  array<int, PlanFeatureKey>  $keys
     * @param  array<int, int>|null  $tenantIds
     * @return array<int, array<string, int|null>> tenant id => (feature key => cap)
     */
    public function forKeys(array $keys, ?array $tenantIds = null): array
    {
        $keyValues = array_map(fn (PlanFeatureKey $k) => $k->value, $keys);

        $subscriptions = DB::connection('pgsql')->table('public.subscriptions')
            ->whereIn('status', self::LIVE)
            ->when($tenantIds !== null, fn ($q) => $q->whereIn('tenant_id', $tenantIds ?? []))
            ->orderBy('id')
            ->get(['tenant_id', 'plan_id', 'feature_overrides']);

        $planIds = array_values(array_unique(array_map(fn ($s): int => (int) $s->plan_id, $subscriptions->all())));
        $features = [];

        if ($planIds !== []) {
            foreach (DB::connection('pgsql')->table('public.plan_features')->whereIn('plan_id', $planIds)->whereIn('feature_key', $keyValues)->get(['plan_id', 'feature_key', 'limit_value', 'enabled']) as $row) {
                $features[(int) $row->plan_id][(string) $row->feature_key] = [
                    'limit_value' => $row->limit_value === null ? null : (int) $row->limit_value,
                    'enabled' => (bool) $row->enabled,
                ];
            }
        }

        $out = [];

        foreach ($subscriptions as $subscription) {
            $tenantId = (int) $subscription->tenant_id;
            $planId = (int) $subscription->plan_id;
            $decoded = is_string($subscription->feature_overrides) ? json_decode($subscription->feature_overrides, true) : $subscription->feature_overrides;
            /** @var array<string, mixed> $overrides */
            $overrides = is_array($decoded) ? $decoded : [];
            $out[$tenantId] ??= [];

            foreach ($keyValues as $key) {
                $row = $features[$planId][$key] ?? null;
                $override = is_array($overrides[$key] ?? null) ? $overrides[$key] : null;

                if ($row === null && $override === null) {
                    continue;
                }

                $enabled = $override !== null && array_key_exists('enabled', $override)
                    ? (bool) $override['enabled']
                    : (bool) ($row['enabled'] ?? true);

                $value = $override !== null && array_key_exists('limit_value', $override)
                    ? ($override['limit_value'] === null ? null : (int) $override['limit_value'])
                    : ($row === null || $row['limit_value'] === null ? null : (int) $row['limit_value']);

                $seen = array_key_exists($key, $out[$tenantId]);
                $out[$tenantId][$key] = $this->moreGenerous($out[$tenantId][$key] ?? 0, $enabled ? $value : 0, $seen);
            }
        }

        return $out;
    }

    private function moreGenerous(?int $current, ?int $candidate, bool $seen): ?int
    {
        if (! $seen) {
            return $candidate;
        }

        if ($current === null || $candidate === null) {
            return null;
        }

        return max($current, $candidate);
    }
}
