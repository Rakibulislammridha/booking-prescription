<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Actions\Plans;

use App\Domain\Audit\Enums\CentralAuditAction;
use App\Domain\SaaS\Enums\SubscriptionStatus;
use App\Domain\SaaS\Exceptions\PlanInUse;
use App\Domain\SaaS\Services\CentralAudit;
use App\Models\Central\Plan;
use App\Models\Central\Subscription;
use Carbon\CarbonImmutable;

/**
 * Archive, never delete (SCHEMA §2.2: "Hidden from new sign-ups; existing subscriptions keep it"). The FK from
 * `subscriptions.plan_id` is RESTRICT, so deleting a plan a clinic is on is not even possible — which is correct:
 * an invoice that names a plan that no longer exists is an unanswerable customer question.
 *
 * The GUARD: archiving a plan that still has live subscribers is legitimate (you stop selling Basic, the
 * clinics on it stay on it), but it must be a decision, not a slip. Unless the caller passes `confirmed`, a plan
 * with live subscriptions refuses with `PlanInUse`, which the console turns into a dialog that names the count.
 */
final class ArchivePlan
{
    public function __construct(private readonly CentralAudit $audit) {}

    /** @throws PlanInUse when archiving a plan with live subscribers without confirming it */
    public function handle(Plan $plan, bool $archived = true, bool $confirmed = false): Plan
    {
        $live = $this->liveSubscriptions($plan);

        if ($archived && $plan->archived_at === null && $live > 0 && ! $confirmed) {
            throw new PlanInUse($plan->code, $live);
        }

        $before = ['archived_at' => $plan->archived_at?->toIso8601String(), 'is_public' => $plan->is_public];

        $plan->forceFill([
            'archived_at' => $archived ? ($plan->archived_at ?? CarbonImmutable::now()) : null,
            'is_public' => $archived ? false : $plan->is_public,
        ])->save();

        $this->audit->record(CentralAuditAction::Update, null, $plan, $before, [
            'archived' => $archived,
            'archived_at' => $plan->archived_at?->toIso8601String(),
            'is_public' => $plan->is_public,
            'live_subscriptions' => $live,
        ]);

        return $plan;
    }

    public function liveSubscriptions(Plan $plan): int
    {
        return Subscription::query()
            ->where('plan_id', $plan->id)
            ->whereIn('status', self::liveStatuses())
            ->count();
    }

    /**
     * The statuses under which a clinic still depends on the plan's rows.
     *
     * @return array<int, string>
     */
    public static function liveStatuses(): array
    {
        return [SubscriptionStatus::Trialing->value, SubscriptionStatus::Active->value, SubscriptionStatus::PastDue->value, SubscriptionStatus::Suspended->value];
    }
}
