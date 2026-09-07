<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Actions;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Notifications\Enums\NotificationStatus;
use App\Domain\Notifications\Jobs\SendNotificationJob;
use App\Domain\Shared\Actor;
use App\Models\Tenant\Notification;

/**
 * Re-queue a dead-lettered message from the panel. The attempt counter is reset so the operator gets a full ladder
 * again — they have presumably fixed whatever caused the rejection (a wrong number, an expired token) — and the
 * `notification_logs` history is kept, so the drawer shows the failed attempts and the new ones side by side.
 */
final class RetryNotification
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function handle(Notification $notification, Actor $actor): Notification
    {
        if (! in_array($notification->status, [NotificationStatus::Failed, NotificationStatus::Cancelled], true)) {
            return $notification;
        }

        $before = ['status' => $notification->status->value, 'attempts' => $notification->attempts];

        $notification->forceFill([
            'status' => NotificationStatus::Queued,
            'attempts' => 0,
            'last_error' => null,
            'scheduled_for' => null,
        ])->save();

        $this->audit->record(AuditAction::Update, $notification, $before, ['status' => NotificationStatus::Queued->value, 'retried_by' => $actor->userId]);

        SendNotificationJob::dispatch($notification->id)->onQueue('notifications');

        return $notification->refresh();
    }
}
