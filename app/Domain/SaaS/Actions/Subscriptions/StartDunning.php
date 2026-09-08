<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Actions\Subscriptions;

use App\Domain\SaaS\Enums\SubscriptionInvoiceStatus;
use App\Domain\SaaS\Enums\SubscriptionStatus;
use App\Domain\SaaS\Events\DunningNoticeDue;
use App\Domain\SaaS\Services\SubscriptionLifecycle;
use App\Domain\SaaS\Support\DunningSchedule;
use App\Models\Central\SubscriptionInvoice;
use Carbon\CarbonImmutable;

/**
 * Moves an unpaid, past-due invoice one rung up the ladder and — the first time — moves the subscription to
 * `past_due` with the grace deadline stamped on it.
 *
 * `dunning_step` on the invoice is the ratchet: the sweep can run every hour, or twice at once, and a clinic still
 * receives each reminder exactly once, because a step is only emitted when it is strictly higher than the step
 * already recorded.
 */
final class StartDunning
{
    public function __construct(private readonly SubscriptionLifecycle $lifecycle) {}

    public function handle(SubscriptionInvoice $invoice, ?CarbonImmutable $at = null): SubscriptionInvoice
    {
        $now = $at ?? CarbonImmutable::now();
        $dueAt = $invoice->getAttribute('due_at');

        if (! $dueAt instanceof CarbonImmutable || $now->lessThan($dueAt)) {
            return $invoice;
        }

        if (! in_array($invoice->status, [SubscriptionInvoiceStatus::Issued, SubscriptionInvoiceStatus::Overdue], true)) {
            return $invoice;
        }

        $step = DunningSchedule::dueStep($dueAt, $now);
        $grace = DunningSchedule::graceDeadline($dueAt);

        if ($invoice->status === SubscriptionInvoiceStatus::Issued) {
            $invoice->forceFill(['status' => SubscriptionInvoiceStatus::Overdue])->save();
        }

        $subscription = $invoice->subscription;

        if ($subscription !== null && in_array($subscription->status, [SubscriptionStatus::Active, SubscriptionStatus::Trialing, SubscriptionStatus::PastDue], true)) {
            $this->lifecycle->transition($subscription, SubscriptionStatus::PastDue, ['grace_until' => $grace]);
        }

        if ($step > (int) $invoice->getAttribute('dunning_step')) {
            $invoice->forceFill(['dunning_step' => $step])->save();

            DunningNoticeDue::dispatch(
                $invoice->tenant_id,
                $invoice->id,
                $step,
                DunningSchedule::isFinalNotice($step),
                $grace->toIso8601String(),
            );
        }

        return $invoice;
    }
}
