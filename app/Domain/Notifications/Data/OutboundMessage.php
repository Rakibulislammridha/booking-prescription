<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Data;

use App\Domain\Notifications\Enums\NotificationChannel;
use App\Domain\Notifications\Enums\NotificationEvent;
use App\Models\Tenant\Notification;

/**
 * What a driver is asked to deliver — the rendered message, never a model. Drivers receive this and nothing else,
 * so a driver can be exercised without a database and can never reach for tenant state of its own.
 */
final readonly class OutboundMessage
{
    /** @param  array<string, mixed>  $payload */
    public function __construct(
        public NotificationChannel $channel,
        public NotificationEvent $event,
        public string $recipient,
        public string $body,
        public ?string $subject = null,
        public string $locale = 'bn',
        public array $payload = [],
        public ?string $providerTemplateId = null,
        public ?int $notificationId = null,
        public int $attemptNo = 1,
    ) {}

    public static function fromNotification(Notification $notification, int $attemptNo): self
    {
        /** @var array<string, mixed> $payload */
        $payload = $notification->payload;

        return new self(
            channel: $notification->channel,
            event: $notification->event_key,
            recipient: $notification->recipient,
            body: $notification->body,
            subject: $notification->subject,
            locale: $notification->locale->value,
            payload: $payload,
            providerTemplateId: is_string($payload['provider_template_id'] ?? null) ? $payload['provider_template_id'] : null,
            notificationId: $notification->id,
            attemptNo: $attemptNo,
        );
    }

    /** A stable, ≤20-char client reference the gateway echoes back (SSL Wireless `csms_id`, WhatsApp biz id). */
    public function clientReference(): string
    {
        return substr('bp'.($this->notificationId ?? 0).'-'.$this->attemptNo, 0, 20);
    }

    /** @return array<int, string> ordered template parameters for providers that take positional variables */
    public function templateParams(): array
    {
        $params = $this->payload['template_params'] ?? [];

        return is_array($params) ? array_values(array_map(fn ($v): string => (string) $v, $params)) : [];
    }
}
