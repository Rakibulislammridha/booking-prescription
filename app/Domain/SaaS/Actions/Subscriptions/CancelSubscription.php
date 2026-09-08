<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Actions\Subscriptions;

use App\Domain\Audit\Enums\CentralAuditAction;
use App\Domain\SaaS\Enums\SubscriptionStatus;
use App\Domain\SaaS\Services\CentralAudit;
use App\Domain\SaaS\Services\SubscriptionLifecycle;
use App\Models\Central\Subscription;
use App\Models\Central\Tenant;
use Carbon\CarbonImmutable;

/**
 * Cancellation, either now or at the end of the paid period.
 *
 * Cancelling NOW makes the tenant `cancelled`, which `EnsureTenantIsActive` answers with a 404 — the clinic is
 * off the internet. Nothing is deleted: the schema, the backups and the churn export (BRIEF §5.N) all survive,
 * and a super admin can reactivate. That asymmetry is on purpose — losing a clinic's records is not a thing a
 * cancellation button should be able to do.
 */
final class CancelSubscription
{
    public function __construct(
        private readonly SubscriptionLifecycle $lifecycle,
        private readonly CentralAudit $audit,
    ) {}

    public function handle(Tenant $tenant, string $reason, bool $immediately = false): ?Subscription
    {
        $subscription = $this->lifecycle->current($tenant);

        if ($subscription === null) {
            return null;
        }

        $before = ['status' => $subscription->status->value, 'cancel_at_period_end' => (bool) $subscription->getAttribute('cancel_at_period_end')];

        if (! $immediately) {
            $subscription->forceFill([
                'cancel_at_period_end' => true,
                'auto_renew' => false,
                'cancel_reason' => mb_substr($reason, 0, 255),
            ])->save();

            $this->audit->record(CentralAuditAction::Update, $tenant, $subscription, $before, ['cancel_at_period_end' => true, 'reason' => $reason]);

            return $subscription;
        }

        $this->lifecycle->transition($subscription, SubscriptionStatus::Cancelled, [
            'cancelled_at' => CarbonImmutable::now(),
            'cancel_reason' => mb_substr($reason, 0, 255),
            'auto_renew' => false,
            'cancel_at_period_end' => false,
            'grace_until' => null,
        ]);

        $this->audit->record(CentralAuditAction::Delete, $tenant, $subscription, $before, ['status' => SubscriptionStatus::Cancelled->value, 'reason' => $reason]);

        return $subscription;
    }
}
