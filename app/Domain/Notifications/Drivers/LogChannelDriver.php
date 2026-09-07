<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Drivers;

use App\Domain\Notifications\Contracts\ChannelDriver;
use App\Domain\Notifications\Data\DeliveryResult;
use App\Domain\Notifications\Data\OutboundMessage;
use App\Domain\Notifications\Enums\NotificationChannel;
use App\Domain\Patients\Services\MobileNumber;
use App\Tenancy\Facades\Tenancy;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The `log` variant every channel falls back to when the tenant has configured no gateway — and the driver the
 * whole test suite runs on. It succeeds, so a clinic without credentials still gets a complete `notifications`
 * ledger and a visible outbound log instead of silent nothing.
 *
 * CONVENTIONS §11: no PII in logs — the recipient is masked and the body is never logged, only its length.
 */
final class LogChannelDriver implements ChannelDriver
{
    /** @var array<int, array{channel: string, recipient: string, body: string, subject: string|null, event: string}> */
    public array $sent = [];

    public function __construct(private readonly NotificationChannel $channel, private readonly bool $writeLog = true) {}

    public function channel(): NotificationChannel
    {
        return $this->channel;
    }

    public function provider(): string
    {
        return 'log';
    }

    public function send(OutboundMessage $message): DeliveryResult
    {
        $this->sent[] = [
            'channel' => $this->channel->value,
            'recipient' => $message->recipient,
            'body' => $message->body,
            'subject' => $message->subject,
            'event' => $message->event->value,
        ];

        if ($this->writeLog) {
            Log::info('notifications.log_driver.sent', [
                'tenant_id' => Tenancy::id(),
                'channel' => $this->channel->value,
                'event' => $message->event->value,
                'recipient' => self::mask($message->recipient),
                'body_length' => mb_strlen($message->body),
                'notification_id' => $message->notificationId,
            ]);
        }

        return DeliveryResult::sent('log-'.Str::lower((string) Str::ulid()), ['driver' => 'log'], 0)
            ->withRequest(['channel' => $this->channel->value, 'to' => self::mask($message->recipient)]);
    }

    public static function mask(string $recipient): string
    {
        if (str_contains($recipient, '@')) {
            return (string) preg_replace('/^(.).*(@.*)$/', '$1***$2', $recipient);
        }

        if (str_starts_with($recipient, 'https://') || str_starts_with($recipient, 'http://')) {
            return (string) parse_url($recipient, PHP_URL_HOST).'/…';
        }

        return MobileNumber::mask($recipient);
    }
}
