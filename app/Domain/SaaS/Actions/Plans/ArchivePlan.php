<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Actions\Plans;

use App\Domain\Audit\Enums\CentralAuditAction;
use App\Domain\SaaS\Enums\SubscriptionStatus;
use App\Domain\SaaS\Services\CentralAudit;
use App\Models\Central\Plan;
use App\Models\Central\Subscription;
use Carbon\CarbonImmutable;

/**
 * Archive, never delete (SCHEMA §2.2: "Hidden from new sign-ups; existing subscriptions keep it"). The FK from
 * `subscriptions.plan_id` is RESTRICT, so deleting a plan a clinic is on is not even possible — which is correct:
 * an invoice that names a plan that no longer exists is an unanswerable customer question.
 */
final class ArchivePlan
{
    public function __construct(private readonly CentralAudit $audit) {}

    public function handle(Plan $plan, bool $archived = true): Plan
    {
        $plan->forceFill([
            'archived_at' => $archived ? CarbonImmutable::now() : null,
            'is_public' => $archived ? false : $plan->is_public,
        ])->save();

        $this->audit->record(CentralAuditAction::Update, null, $plan, null, ['archived' => $archived, 'live_subscriptions' => $this->liveSubscriptions($plan)]);

        return $plan;
    }

    public function liveSubscriptions(Plan $plan): int
    {
        return Subscription::query()
            ->where('plan_id', $plan->id)
            ->whereIn('status', [SubscriptionStatus::Trialing->value, SubscriptionStatus::Active->value, SubscriptionStatus::PastDue->value, SubscriptionStatus::Suspended->value])
            ->count();
    }
}
