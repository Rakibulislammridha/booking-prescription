<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Jobs;

use App\Domain\Notifications\Actions\SendNotification;
use App\Domain\Notifications\Enums\NotificationStatus;
use App\Domain\Notifications\Exceptions\GatewayRateLimited;
use App\Models\Tenant\Notification;
use App\Tenancy\Facades\Tenancy;
use App\Tenancy\Queue\TenantAware;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Horizon queue `notifications` (ARCHITECTURE §4.6). Idempotent by construction: it carries an id, re-reads the row
 * and returns immediately if the row has already reached a terminal state — a duplicate dispatch cannot double-send.
 *
 * The backoff ladder is `notifications.retry.backoff` (1 min → 5 min → 15 min). A PERMANENT provider rejection is
 * not raised as an exception at all: SendNotification records it and dead-letters, and the job succeeds, because
 * re-queuing a message the gateway has definitively refused is how a queue turns into a mailbomb.
 */
final class SendNotificationJob implements ShouldQueue
{
    use Queueable, TenantAware;

    public int $timeout = 60;

    public function __construct(public readonly int $notificationId)
    {
        $this->onQueue('notifications');

        if (Tenancy::check()) {
            $this->forTenant((int) Tenancy::id());
        }
    }

    public function tries(): int
    {
        return max(1, (int) config('notifications.retry.tries', 4));
    }

    /** @return array<int, int> */
    public function backoff(): array
    {
        /** @var array<int, int> $ladder */
        $ladder = (array) config('notifications.retry.backoff', [60, 300, 900]);

        return $ladder === [] ? [60] : array_map('intval', $ladder);
    }

    public function handle(SendNotification $send): void
    {
        $notification = Notification::query()->find($this->notificationId);

        if ($notification === null || $notification->status->isTerminal() || $notification->status === NotificationStatus::Sent) {
            return;
        }

        try {
            $notification = $send->handle($notification);
        } catch (GatewayRateLimited $e) {
            $this->release($e->retryAfterSeconds);

            return;
        }

        // Transient failure: let the queue's own backoff ladder bring it back rather than releasing by hand, so
        // Horizon's attempt count and ours stay the same number.
        if ($notification->status === NotificationStatus::Queued) {
            Log::warning('notifications.attempt.failed', [
                'tenant_id' => Tenancy::id(),
                'notification_id' => $notification->id,
                'attempt' => $notification->attempts,
                'error' => $notification->last_error,
            ]);

            $this->release($this->backoffFor($notification->attempts));
        }
    }

    private function backoffFor(int $attempts): int
    {
        $ladder = $this->backoff();

        return $ladder[min(max($attempts - 1, 0), count($ladder) - 1)];
    }
}
