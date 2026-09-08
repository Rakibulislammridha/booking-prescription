<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Listeners;

use App\Domain\Notifications\Enums\NotificationChannel;
use App\Domain\Notifications\Events\NotificationDeadLettered;
use App\Domain\SaaS\Enums\UsageMetric;
use App\Domain\SaaS\Services\PlanLimits;
use App\Models\Tenant\Notification;
use App\Tenancy\Facades\Tenancy;

/**
 * SMS credits are taken when the message is queued (that is the only moment the answer can still be "no"), so a
 * message the gateway PERMANENTLY refused — a dead number, a rejected template — has to give them back. A clinic
 * must not be billed for segments that never left the gateway.
 *
 * A message that was never charged in the first place (`sms_credits_exhausted`) is skipped, or the release would
 * hand back credits that were never taken.
 */
final class ReleaseSmsCreditsOnDeadLetter
{
    public function __construct(private readonly PlanLimits $limits) {}

    public function handle(NotificationDeadLettered $event): void
    {
        $tenant = Tenancy::current();

        if ($tenant === null) {
            return;
        }

        $notification = Notification::query()->find($event->notificationId);

        if ($notification === null
            || $notification->channel !== NotificationChannel::Sms
            || $notification->last_error === 'sms_credits_exhausted') {
            return;
        }

        $this->limits->release($tenant, UsageMetric::SmsCredits, max(1, (int) ($notification->segments ?? 1)));
    }
}
