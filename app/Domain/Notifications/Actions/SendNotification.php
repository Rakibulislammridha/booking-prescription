<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Actions;

use App\Domain\Notifications\Contracts\DriverFactory;
use App\Domain\Notifications\Data\DeliveryResult;
use App\Domain\Notifications\Data\OutboundMessage;
use App\Domain\Notifications\Enums\NotificationChannel;
use App\Domain\Notifications\Enums\NotificationStatus;
use App\Domain\Notifications\Events\NotificationDeadLettered;
use App\Domain\Notifications\Events\NotificationSent;
use App\Domain\Notifications\Events\SmsSent;
use App\Domain\Notifications\Exceptions\GatewayRateLimited;
use App\Domain\Notifications\Services\NotificationRateLimiter;
use App\Models\Tenant\Notification;
use App\Models\Tenant\NotificationLog;
use App\Tenancy\Facades\Tenancy;
use Carbon\CarbonImmutable;

/**
 * ONE delivery attempt, logged. Everything about retry policy is decided here and merely obeyed by the job:
 *
 *  - `attempts` is incremented before the call, so a worker that dies mid-request still burns an attempt and a
 *    poisoned message cannot loop forever;
 *  - every attempt writes a `notification_logs` row with the sanitised request, the provider response, the error
 *    code and the latency — the drawer in the panel is literally this table;
 *  - a PERMANENT rejection sets `failed` immediately (dead letter). A transient failure leaves the row `queued`
 *    until the ladder is exhausted, and only then dead-letters;
 *  - the per-tenant rate limiter throws BEFORE an attempt is burned, so a throttled clinic loses no retries.
 */
final class SendNotification
{
    public function __construct(
        private readonly DriverFactory $drivers,
        private readonly NotificationRateLimiter $limiter,
    ) {}

    /** @throws GatewayRateLimited when this tenant has exhausted its per-minute allowance for the channel */
    public function handle(Notification $notification): Notification
    {
        if ($notification->status->isTerminal() || $notification->status === NotificationStatus::Sent) {
            return $notification;
        }

        $driver = $this->drivers->for($notification->channel);

        if (! $this->limiter->attempt($notification->channel)) {
            throw new GatewayRateLimited($notification->channel, $this->limiter->availableIn($notification->channel));
        }

        $attemptNo = $notification->attempts + 1;
        $notification->forceFill(['attempts' => $attemptNo, 'status' => NotificationStatus::Sending])->save();

        $result = $driver->send(OutboundMessage::fromNotification($notification, $attemptNo));

        $this->log($notification, $attemptNo, $driver->provider(), $result);

        return $result->isSuccess()
            ? $this->succeed($notification, $result)
            : $this->fail($notification, $result, $attemptNo);
    }

    private function succeed(Notification $notification, DeliveryResult $result): Notification
    {
        $notification->forceFill([
            'status' => NotificationStatus::Sent,
            'sent_at' => CarbonImmutable::now(),
            'last_error' => null,
            'cost_paisa' => $result->costPaisa ?? $notification->cost_paisa,
        ])->save();

        NotificationSent::dispatch(
            (int) Tenancy::id(),
            $notification->id,
            $notification->event_key->value,
            $notification->channel->value,
            $notification->notifiable_type,
            $notification->notifiable_id,
        );

        if ($notification->channel === NotificationChannel::Sms) {
            // ARCHITECTURE §5.4: SaaS meters sms_credits off this event, so it fires on gateway success only.
            SmsSent::dispatch((int) Tenancy::id(), $notification->id, (int) ($notification->segments ?? 1));
        }

        return $notification;
    }

    private function fail(Notification $notification, DeliveryResult $result, int $attemptNo): Notification
    {
        $maxTries = max(1, (int) config('notifications.retry.tries', 4));
        $exhausted = $result->permanent || $attemptNo >= $maxTries;

        $notification->forceFill([
            'status' => $exhausted ? NotificationStatus::Failed : NotificationStatus::Queued,
            'last_error' => mb_substr((string) ($result->errorCode ?? 'unknown_error'), 0, 255),
        ])->save();

        if ($exhausted) {
            NotificationDeadLettered::dispatch($notification->id, (string) $result->errorCode, $result->permanent);
        }

        return $notification;
    }

    private function log(Notification $notification, int $attemptNo, string $provider, DeliveryResult $result): void
    {
        NotificationLog::query()->create([
            'notification_id' => $notification->id,
            'attempt_no' => $attemptNo,
            'provider' => mb_substr($provider, 0, 32),
            'provider_message_id' => $result->providerMessageId === null ? null : mb_substr($result->providerMessageId, 0, 128),
            'status' => $result->status,
            'request' => $result->request,
            'response' => $result->response,
            'error_code' => $result->errorCode === null ? null : mb_substr($result->errorCode, 0, 64),
            'latency_ms' => $result->latencyMs,
        ]);
    }
}
