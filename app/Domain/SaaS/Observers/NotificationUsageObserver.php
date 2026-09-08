<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Observers;

use App\Domain\Notifications\Enums\NotificationChannel;
use App\Domain\Notifications\Enums\NotificationStatus;
use App\Domain\SaaS\Enums\UsageMetric;
use App\Domain\SaaS\Exceptions\PlanLimitExceeded;
use App\Domain\SaaS\Observers\Concerns\MetersTenantUsage;
use App\Models\Tenant\Notification;

/**
 * SMS credits are the one cap that must NOT throw (SCHEMA §5.8): "SMS over the limit are stored as
 * `notifications.status='failed'`, `last_error='sms_credits_exhausted'`". A patient's booking must not fail
 * because the clinic ran out of SMS — the message fails, visibly, in the outbound log the panel already shows.
 *
 * The credit is taken when the row is written, not when the gateway confirms, because that is the only moment at
 * which the answer can still be "no" and the only moment at which two producers can be serialised. A message the
 * gateway permanently refuses gives its credits back (`ReleaseSmsCreditsOnDeadLetter`).
 */
final class NotificationUsageObserver
{
    use MetersTenantUsage;

    public function creating(Notification $notification): void
    {
        if ($notification->channel !== NotificationChannel::Sms) {
            return;
        }

        try {
            $this->reserve(UsageMetric::SmsCredits, max(1, (int) ($notification->segments ?? 1)));
        } catch (PlanLimitExceeded) {
            $notification->setAttribute('status', NotificationStatus::Failed);
            $notification->setAttribute('last_error', 'sms_credits_exhausted');
            $notification->setAttribute('scheduled_for', null);
        }
    }
}
