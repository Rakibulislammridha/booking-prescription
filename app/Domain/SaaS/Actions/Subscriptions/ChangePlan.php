<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Actions\Subscriptions;

use App\Domain\Audit\Enums\CentralAuditAction;
use App\Domain\SaaS\Enums\BillingCycle;
use App\Domain\SaaS\Events\SubscriptionPlanChanged;
use App\Domain\SaaS\Services\CentralAudit;
use App\Domain\SaaS\Services\FeatureFlagCache;
use App\Domain\SaaS\Services\SubscriptionLifecycle;
use App\Models\Central\Plan;
use App\Models\Central\Subscription;
use App\Models\Central\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * Move a tenant between plans.
 *
 * PRORATION: none, deliberately.
 *
 *   · Entitlements change the moment the plan does — an upgrade is usable immediately, for the rest of the period
 *     the clinic already paid for, at no extra charge.
 *   · The PRICE changes at the next renewal. No mid-period charge, no credit note, no proration line.
 *   · A downgrade therefore keeps the better plan's caps until the paid period ends, which is what the customer
 *     paid for and what avoids "I paid for Pro and lost my third branch on day 2".
 *
 * Why not prorate: the platform bills BDT in whole monthly or yearly periods, collection is by bKash/Nagad/bank
 * transfer rather than a stored card, and there is no refund rail for a mid-period credit. A proration line would
 * therefore be an IOU nobody can settle, and "your new price starts next month" is a sentence a clinic manager
 * can check against their bank statement. If the platform ever stores payment instruments, the proration delta is
 * one extra invoice line here and nothing else changes.
 *
 * A downgrade that would put the tenant OVER the new plan's caps is allowed: existing rows are never deleted to
 * fit a plan. The caps bite on the next write, and the super console shows the over-limit metrics in red.
 */
final class ChangePlan
{
    public function __construct(
        private readonly SubscriptionLifecycle $lifecycle,
        private readonly CentralAudit $audit,
        private readonly FeatureFlagCache $flags,
    ) {}

    public function handle(Tenant $tenant, Plan $plan, ?BillingCycle $cycle = null): Subscription
    {
        $subscription = $this->lifecycle->current($tenant);

        if ($subscription === null) {
            throw new \RuntimeException('tenant '.$tenant->id.' has no current subscription');
        }

        $fromCode = $subscription->plan->code;
        $cycle ??= $subscription->billing_cycle;
        $price = $cycle === BillingCycle::Yearly ? $plan->price_yearly_paisa : $plan->price_monthly_paisa;

        DB::connection('pgsql')->transaction(function () use ($subscription, $plan, $cycle, $price): void {
            $subscription->forceFill([
                'plan_id' => $plan->id,
                'billing_cycle' => $cycle,
                'price_paisa' => $price,
            ])->save();
        });

        $this->flags->forTenant($tenant);

        $this->audit->record(
            CentralAuditAction::PlanChange,
            $tenant,
            $subscription,
            ['plan' => $fromCode, 'price_paisa' => $subscription->getOriginal('price_paisa')],
            ['plan' => $plan->code, 'price_paisa' => $price, 'billing_cycle' => $cycle->value],
        );

        SubscriptionPlanChanged::dispatch($tenant->id, $subscription->id, $fromCode, $plan->code);

        return $subscription->refresh();
    }
}
