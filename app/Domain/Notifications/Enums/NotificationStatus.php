<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Enums;

/** `notifications.status` (SCHEMA §3.6). `failed` after the bounded retry policy gives up is the dead-letter state. */
enum NotificationStatus: string
{
    case Queued = 'queued';
    case Scheduled = 'scheduled';
    case Sending = 'sending';
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }

    /**
     * Rows the dispatcher may pick up (the partial index `notifications_status_scheduled_for_idx_p`).
     *
     * @return array<int, string>
     */
    public static function pending(): array
    {
        return [self::Queued->value, self::Scheduled->value];
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Delivered, self::Failed, self::Cancelled], true);
    }
}
