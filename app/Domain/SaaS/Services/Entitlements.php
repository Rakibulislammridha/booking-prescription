<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Services;

use App\Domain\SaaS\Data\PlanEntitlements;
use App\Domain\SaaS\Enums\PlanFeatureKey;
use App\Domain\SaaS\Enums\SubscriptionStatus;
use App\Models\Central\Plan;
use App\Models\Central\Subscription;
use App\Models\Central\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * Resolves `plan_features` + `subscriptions.feature_overrides` into a `PlanEntitlements` for one tenant.
 *
 * Registered `scoped` (ARCHITECTURE §4.5): the per-tenant answer is memoised for one request / job / Octane
 * operation and forgotten with the container, and `TenancyInitialized` / `TenancyEnded` flush it as well — a
 * `Tenancy::run()` in the middle of a super-admin request must not leave another clinic's entitlements behind.
 *
 * Every read is one query over `public.*` tables. It is deliberately NOT cached in Redis: a plan change or a
 * super-admin override must be in force on the very next request, and this is two indexed reads.
 */
final class Entitlements
{
    /** @var array<int, PlanEntitlements> */
    private array $cache = [];

    /** Statuses that entitle: a suspended or cancelled subscription grants nothing. */
    private const LIVE = [SubscriptionStatus::Trialing, SubscriptionStatus::Active, SubscriptionStatus::PastDue];

    public function for(Tenant $tenant): PlanEntitlements
    {
        return $this->cache[$tenant->id] ??= $this->resolve($tenant);
    }

    public function forget(?int $tenantId = null): void
    {
        if ($tenantId === null) {
            $this->cache = [];

            return;
        }

        unset($this->cache[$tenantId]);
    }

    private function resolve(Tenant $tenant): PlanEntitlements
    {
        /** @var array<int, Subscription> $subscriptions */
        $subscriptions = Subscription::query()
            ->with('plan')
            ->where('tenant_id', $tenant->id)
            ->whereIn('status', array_map(fn (SubscriptionStatus $s) => $s->value, self::LIVE))
            ->orderBy('id')
            ->get()
            ->all();

        if ($subscriptions === []) {
            return PlanEntitlements::none();
        }

        $planIds = array_values(array_unique(array_map(fn (Subscription $s) => $s->plan_id, $subscriptions)));
        $features = $this->featuresOf($planIds);

        $limits = [];
        $toggles = [];
        $codes = [];
        $base = null;

        foreach ($subscriptions as $subscription) {
            $plan = $subscription->plan;
            $codes[] = $plan->code;
            $base ??= $plan->is_addon ? null : $plan;

            /** @var array<string, array<string, mixed>> $overrides */
            $overrides = (array) $subscription->feature_overrides;

            foreach (PlanFeatureKey::cases() as $key) {
                $row = $features[$plan->id][$key->value] ?? null;
                $override = is_array($overrides[$key->value] ?? null) ? $overrides[$key->value] : null;

                if ($row === null && $override === null) {
                    continue;                                        // this plan says nothing about this key
                }

                if ($key->isToggle()) {
                    $enabled = $override !== null && array_key_exists('enabled', $override)
                        ? (bool) $override['enabled']
                        : (bool) ($row['enabled'] ?? false);

                    $toggles[$key->value] = ($toggles[$key->value] ?? false) || $enabled;

                    continue;
                }

                // A disabled numeric row means "not available at all" — cap 0, not "unlimited".
                $enabled = $override !== null && array_key_exists('enabled', $override)
                    ? (bool) $override['enabled']
                    : (bool) ($row['enabled'] ?? true);

                $value = $override !== null && array_key_exists('limit_value', $override)
                    ? ($override['limit_value'] === null ? null : (int) $override['limit_value'])
                    : ($row === null || $row['limit_value'] === null ? null : (int) $row['limit_value']);

                $limits[$key->value] = $this->moreGenerous($limits[$key->value] ?? 0, $enabled ? $value : 0, array_key_exists($key->value, $limits));
            }
        }

        $base ??= $subscriptions[0]->plan;

        return new PlanEntitlements(
            planCode: $base->code,
            planName: $base->name,
            limits: $limits,
            toggles: $toggles,
            planCodes: array_values(array_unique($codes)),
        );
    }

    /** null (unlimited) beats every number; otherwise the larger cap wins. */
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

    /**
     * @param  array<int, int>  $planIds
     * @return array<int, array<string, array{limit_value: int|null, enabled: bool}>>
     */
    private function featuresOf(array $planIds): array
    {
        if ($planIds === []) {
            return [];
        }

        $rows = DB::connection('pgsql')->table('public.plan_features')
            ->whereIn('plan_id', $planIds)
            ->get(['plan_id', 'feature_key', 'limit_value', 'enabled']);

        $out = [];

        foreach ($rows as $row) {
            $out[(int) $row->plan_id][(string) $row->feature_key] = [
                'limit_value' => $row->limit_value === null ? null : (int) $row->limit_value,
                'enabled' => (bool) $row->enabled,
            ];
        }

        return $out;
    }

    /** @return array<int, Plan> every public, non-archived plan, cheapest first — the pricing page's source */
    public function publicPlans(): array
    {
        return Plan::query()
            ->with('features')
            ->whereNull('archived_at')
            ->where('is_public', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get()
            ->all();
    }
}
