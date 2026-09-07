<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Listeners;

use App\Domain\Notifications\Enums\NotificationEvent;
use App\Domain\Notifications\Enums\NotificationStatus;
use App\Domain\Serials\Events\SerialCancelled;
use App\Domain\Serials\Events\SerialNoShow;
use App\Models\Tenant\Notification;
use App\Tenancy\Facades\Tenancy;

/**
 * A patient whose serial is cancelled must not receive tomorrow's "your appointment is tomorrow" reminder. Any
 * still-`scheduled` reminder for that serial becomes `cancelled` — visible in the outbound log as a suppression,
 * not silently deleted. Messages already sent are history and are left alone.
 *
 * Runs synchronously: it is one indexed UPDATE-shaped read on rows the cancel path just touched.
 */
final class CancelPendingNotifications
{
    private const REMINDERS = [
        NotificationEvent::ReminderDayBefore->value,
        NotificationEvent::ReminderMorning->value,
        NotificationEvent::ThreeAhead->value,
    ];

    public function handle(SerialCancelled|SerialNoShow $event): void
    {
        if (! Tenancy::check()) {
            return;
        }

        $pending = Notification::query()
            ->where('serial_id', $event->serialId)
            ->whereIn('event_key', self::REMINDERS)
            ->whereIn('status', NotificationStatus::pending())
            ->get();

        foreach ($pending as $notification) {
            $notification->forceFill(['status' => NotificationStatus::Cancelled, 'last_error' => 'serial_'.($event instanceof SerialCancelled ? 'cancelled' : 'no_show')])->save();
        }
    }
}
