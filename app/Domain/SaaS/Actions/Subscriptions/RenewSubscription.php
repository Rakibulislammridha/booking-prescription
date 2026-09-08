<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Actions\Subscriptions;

use App\Domain\SaaS\Actions\Billing\GenerateSubscriptionInvoice;
use App\Domain\SaaS\Actions\Billing\IssueSubscriptionInvoice;
use App\Domain\SaaS\Enums\SubscriptionStatus;
use App\Domain\SaaS\Services\SubscriptionLifecycle;
use App\Domain\SaaS\Support\DunningSchedule;
use App\Models\Central\Subscription;
use Carbon\CarbonImmutable;

/**
 * The period rolled over on an `active` subscription: issue the next invoice and advance the window.
 *
 * The subscription stays ACTIVE while the invoice is merely outstanding — a clinic that pays on the 5th of every
 * month must not spend the 1st to the 5th looking at a dunning banner. It only becomes `past_due` when the
 * invoice passes its due date unpaid, which is `StartDunning`'s job.
 *
 * `cancel_at_period_end` is honoured here rather than by a separate sweep: the period ending IS the cancellation.
 */
final class RenewSubscription
{
    public function __construct(
        private readonly SubscriptionLifecycle $lifecycle,
        private readonly GenerateSubscriptionInvoice $generate,
        private readonly IssueSubscriptionInvoice $issue,
    ) {}

    public function handle(Subscription $subscription, ?CarbonImmutable $at = null): Subscription
    {
        if ($subscription->status !== SubscriptionStatus::Active) {
            return $subscription;
        }

        $now = $at ?? CarbonImmutable::now();

        if ($now->lessThan($subscription->current_period_end)) {
            return $subscription;
        }

        if ((bool) $subscription->getAttribute('cancel_at_period_end') || ! (bool) $subscription->getAttribute('auto_renew')) {
            return $this->lifecycle->transition($subscription, SubscriptionStatus::Expired, [
                'cancelled_at' => $now,
                'cancel_reason' => $subscription->getAttribute('cancel_reason') ?? 'saas.cancel.period_end',
            ]);
        }

        $periodStart = $subscription->current_period_end;
        $periodEnd = $this->lifecycle->nextPeriodEnd($subscription, $periodStart);

        $this->issue->handle($this->generate->handle($subscription, $periodStart, $periodEnd), $now->addDays(DunningSchedule::NET_DAYS));

        $subscription->forceFill([
            'current_period_start' => $periodStart,
            'current_period_end' => $periodEnd,
        ])->save();

        return $subscription;
    }
}
