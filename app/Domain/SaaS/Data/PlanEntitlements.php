<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Data;

use App\Domain\SaaS\Enums\PlanFeatureKey;
use App\Domain\SaaS\Enums\UsageMetric;

/**
 * What one tenant is entitled to right now: `plan_features` of every LIVE subscription, with
 * `subscriptions.feature_overrides` applied on top (SCHEMA §2.4 — "overrides win over plan_features").
 *
 * Add-ons are separate subscription rows, so entitlements are a UNION: a toggle is on when any live subscription
 * turns it on, and a numeric cap is the most generous of them (`null` = unlimited beats every number). That is the
 * only reading under which "Pro + Telemedicine add-on" means what a customer thinks it means.
 */
final readonly class PlanEntitlements
{
    /**
     * @param  array<string, int|null>  $limits  feature_key => cap; null = unlimited, 0 = disabled
     * @param  array<string, bool>  $toggles  feature_key => enabled
     * @param  array<int, string>  $planCodes  every live plan, base first
     */
    public function __construct(
        public string $planCode,
        public string $planName,
        public array $limits,
        public array $toggles,
        public array $planCodes = [],
        public bool $hasLiveSubscription = true,
    ) {}

    /**
     * No live subscription: module toggles are OFF (a paid module must never leak) but numeric caps are treated as
     * unlimited. A tenant in this state is `suspended` or `cancelled` and cannot reach a write path at all
     * (EnsureTenantIsActive answers 402/404 first), so failing open on counts only avoids bricking a clinic on a
     * control-plane data glitch — it never widens what an unpaid tenant can reach.
     */
    public static function none(): self
    {
        return new self(
            planCode: '',
            planName: '—',
            limits: array_fill_keys(array_map(fn (PlanFeatureKey $k) => $k->value, PlanFeatureKey::limits()), null),
            toggles: array_fill_keys(array_map(fn (PlanFeatureKey $k) => $k->value, PlanFeatureKey::toggles()), false),
            planCodes: [],
            hasLiveSubscription: false,
        );
    }

    /** `null` = unlimited. A key the plan never mentions is unlimited too: a plan opts INTO caps. */
    public function limit(PlanFeatureKey $key): ?int
    {
        return $this->limits[$key->value] ?? null;
    }

    public function limitFor(UsageMetric $metric): ?int
    {
        $key = $metric->limitKey();

        return $key === null ? null : $this->limit($key);
    }

    /** A module toggle the plan does not mention is OFF: modules are opted INTO. */
    public function enabled(PlanFeatureKey $key): bool
    {
        return $this->toggles[$key->value] ?? false;
    }

    /** @return array<string, bool> Pennant name => value, for the shared-props `features` map */
    public function featureMap(): array
    {
        $map = [];

        foreach (PlanFeatureKey::toggles() as $key) {
            $map[(string) $key->featureName()] = $this->enabled($key);
        }

        return $map;
    }
}
