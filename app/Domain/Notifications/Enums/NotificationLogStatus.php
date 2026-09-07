<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Enums;

/** `notification_logs.status` (SCHEMA §3.6). `rejected` is a PERMANENT provider refusal — never retried. */
enum NotificationLogStatus: string
{
    case Sent = 'sent';
    case Delivered = 'delivered';
    case Failed = 'failed';
    case Rejected = 'rejected';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
