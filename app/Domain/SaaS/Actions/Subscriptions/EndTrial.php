<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Actions\Subscriptions;

use App\Domain\SaaS\Actions\Billing\GenerateSubscriptionInvoice;
use App\Domain\SaaS\Actions\Billing\IssueSubscriptionInvoice;
use App\Domain\SaaS\Enums\SubscriptionStatus;
use App\Domain\SaaS\Events\TrialEnded;
use App\Domain\SaaS\Services\SubscriptionLifecycle;
use App\Models\Central\Subscription;
use Carbon\CarbonImmutable;

/**
 * `trialing` → the next thing.
 *
 * A free plan converts straight to `active` — there is nothing to collect, and a clinic on the free tier must not
 * be dunned. A paid plan produces the FIRST invoice and moves to `past_due` with a grace deadline: the clinic
 * keeps working (past_due is served, with a banner) while it pays, and only the grace expiring suspends it.
 *
 * There are no stored payment instruments, so a trial cannot silently start charging a card. That is a feature in
 * this market, not a limitation.
 */
final class EndTrial
{
    public function __construct(
        private readonly SubscriptionLifecycle $lifecycle,
        private readonly GenerateSubscriptionInvoice $generate,
        private readonly IssueSubscriptionInvoice $issue,
        private readonly StartDunning $startDunning,
    ) {}

    public function handle(Subscription $subscription, ?CarbonImmutable $at = null): Subscription
    {
        if ($subscription->status !== SubscriptionStatus::Trialing) {
            return $subscription;
        }

        $now = $at ?? CarbonImmutable::now();
        $periodStart = $now;
        $periodEnd = $this->lifecycle->nextPeriodEnd($subscription, $periodStart);

        if ($this->lifecycle->periodPricePaisa($subscription) === 0) {
            $this->lifecycle->transition($subscription, SubscriptionStatus::Active, [
                'current_period_start' => $periodStart,
                'current_period_end' => $periodEnd,
                'trial_ends_at' => $now,
                'grace_until' => null,
            ]);

            TrialEnded::dispatch($subscription->tenant_id, $subscription->id, true);

            return $subscription;
        }

        $invoice = $this->issue->handle($this->generate->handle($subscription, $periodStart, $periodEnd), $now);

        $this->lifecycle->transition($subscription, SubscriptionStatus::PastDue, [
            'current_period_start' => $periodStart,
            'current_period_end' => $periodEnd,
            'trial_ends_at' => $now,
        ]);

        $this->startDunning->handle($invoice->refresh(), $now);

        TrialEnded::dispatch($subscription->tenant_id, $subscription->id, false);

        return $subscription;
    }
}
