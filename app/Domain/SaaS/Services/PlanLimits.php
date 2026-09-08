<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Services;

use App\Domain\SaaS\Data\LimitStatus;
use App\Domain\SaaS\Data\PlanEntitlements;
use App\Domain\SaaS\Enums\PlanFeatureKey;
use App\Domain\SaaS\Enums\UsageMetric;
use App\Domain\SaaS\Exceptions\FeatureNotInPlan;
use App\Domain\SaaS\Exceptions\PlanLimitExceeded;
use App\Models\Central\Tenant;
use App\Tenancy\Facades\Tenancy;

/**
 * The plan gate (ARCHITECTURE §8.4). Two ways in, and the difference is the whole point:
 *
 *   allows() / assertCanAdd()  — an ADVISORY read. Cheap, no write, right for a form's validation message, a
 *                                disabled button or an early 402 before an expensive action starts. Two callers
 *                                racing here can both be told "yes"; that is what `reserve()` is for.
 *
 *   reserve()                  — the REAL gate. One statement increments the counter and hands back the value
 *                                after the write (UsageMeter::add), so two concurrent callers get two distinct
 *                                numbers and only one of them can be the one that crosses the cap. The loser's
 *                                increment is compensated and it is refused. Nothing else may write the counters
 *                                of a capped metric, because anything else re-opens the race.
 *
 * The compensating decrement is correct in both contexts: inside the caller's transaction it rolls back together
 * with the increment (net zero either way), and outside one it is applied immediately.
 */
final class PlanLimits
{
    public function __construct(
        private readonly Entitlements $entitlements,
        private readonly UsageMeter $meter,
    ) {}

    public function entitlements(Tenant $tenant): PlanEntitlements
    {
        return $this->entitlements->for($tenant);
    }

    /** `null` = unlimited. */
    public function limit(Tenant $tenant, UsageMetric $metric): ?int
    {
        return $this->entitlements->for($tenant)->limitFor($metric);
    }

    public function usage(Tenant $tenant, UsageMetric $metric): int
    {
        return $this->meter->value($tenant, $metric);
    }

    public function remaining(Tenant $tenant, UsageMetric $metric): ?int
    {
        $limit = $this->limit($tenant, $metric);

        return $limit === null ? null : max(0, $limit - $this->usage($tenant, $metric));
    }

    /** Advisory: is there room for `$qty` more right now? */
    public function allows(Tenant $tenant, UsageMetric $metric, int $qty = 1): bool
    {
        $limit = $this->limit($tenant, $metric);

        return $limit === null || $this->usage($tenant, $metric) + $qty <= $limit;
    }

    /**
     * Advisory pre-check. Use it to fail fast and to produce the message; it does NOT hold the room.
     *
     * @throws PlanLimitExceeded
     */
    public function assertCanAdd(Tenant $tenant, UsageMetric $metric, int $qty = 1): void
    {
        $limit = $this->limit($tenant, $metric);

        if ($limit !== null && $this->usage($tenant, $metric) + $qty > $limit) {
            throw $this->exceeded($tenant, $metric, $limit, $this->usage($tenant, $metric));
        }
    }

    /**
     * Take `$qty` of the allowance, atomically, or refuse. Returns the counter value after the write.
     *
     * @throws PlanLimitExceeded
     */
    public function reserve(Tenant $tenant, UsageMetric $metric, int $qty = 1): int
    {
        if ($qty <= 0) {
            return $this->usage($tenant, $metric);
        }

        $limit = $this->limit($tenant, $metric);
        $after = $this->meter->add($tenant, $metric, $qty, $limit);

        if ($limit !== null && $after > $limit) {
            $this->meter->add($tenant, $metric, -$qty, $limit);        // give the allowance back before refusing

            throw $this->exceeded($tenant, $metric, $limit, $after - $qty);
        }

        return $after;
    }

    /** Hand an unused reservation back (a permanently rejected SMS, a deleted doctor, a removed document). */
    public function release(Tenant $tenant, UsageMetric $metric, int $qty = 1): void
    {
        if ($qty > 0) {
            $this->meter->add($tenant, $metric, -$qty, $this->limit($tenant, $metric));
        }
    }

    /** Absolute value — the nightly gauge recount only. */
    public function recount(Tenant $tenant, UsageMetric $metric, int $value): void
    {
        $this->meter->set($tenant, $metric, $value, $this->limit($tenant, $metric));
    }

    public function enabled(Tenant $tenant, PlanFeatureKey $key): bool
    {
        return $this->entitlements->for($tenant)->enabled($key);
    }

    /** @throws FeatureNotInPlan */
    public function assertFeature(Tenant $tenant, PlanFeatureKey $key): void
    {
        if (! $this->enabled($tenant, $key)) {
            throw new FeatureNotInPlan($key, $this->entitlements->for($tenant)->planName);
        }
    }

    /**
     * Usage against limits for every capped metric — the panel's subscription page and the super console's
     * tenant detail render exactly this.
     *
     * @return array<int, LimitStatus>
     */
    public function statuses(Tenant $tenant): array
    {
        $entitlements = $this->entitlements->for($tenant);
        $current = $this->meter->current($tenant);
        $out = [];

        foreach (UsageMetric::cases() as $metric) {
            if (! $metric->isCapped()) {
                continue;
            }

            $out[] = new LimitStatus(
                metric: $metric,
                used: $current[$metric->value] ?? 0,
                limit: $entitlements->limitFor($metric),
                period: $this->meter->period($tenant, $metric),
            );
        }

        return $out;
    }

    /**
     * Every metric, capped or not — the super console's usage dashboard.
     *
     * @return array<int, LimitStatus>
     */
    public function allStatuses(Tenant $tenant): array
    {
        $entitlements = $this->entitlements->for($tenant);
        $current = $this->meter->current($tenant);

        return array_map(fn (UsageMetric $metric) => new LimitStatus(
            metric: $metric,
            used: $current[$metric->value] ?? 0,
            limit: $entitlements->limitFor($metric),
            period: $this->meter->period($tenant, $metric),
        ), UsageMetric::cases());
    }

    /** The tenant whose limits apply to the work in hand: the active tenancy. */
    public function currentTenant(): ?Tenant
    {
        return Tenancy::current();
    }

    private function exceeded(Tenant $tenant, UsageMetric $metric, int $limit, int $current): PlanLimitExceeded
    {
        return new PlanLimitExceeded($metric, $metric->limitKey(), $limit, $current, $this->entitlements->for($tenant)->planName);
    }
}
