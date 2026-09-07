<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Drivers\Mail;

/** Address masking for `notification_logs.request` — the log is readable by staff, the mailbox is not their business. */
final class LogRecipient
{
    public static function mask(string $address): string
    {
        return (string) preg_replace('/^(.).*(@.*)$/', '$1***$2', $address);
    }
}
