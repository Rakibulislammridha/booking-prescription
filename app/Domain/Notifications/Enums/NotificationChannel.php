<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Enums;

/** `notification_templates.channel`, `notifications.channel` (SCHEMA Appendix A). */
enum NotificationChannel: string
{
    case Sms = 'sms';
    case Whatsapp = 'whatsapp';
    case Push = 'push';
    case Email = 'email';
    case Ivr = 'ivr';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }

    /** Channels a tenant configures a gateway row for (`sms_gateway_settings.channel`). */
    public function usesGatewayRow(): bool
    {
        return in_array($this, [self::Sms, self::Whatsapp, self::Ivr], true);
    }

    /** The recipient of an email is an address; every other channel addresses an E.164 mobile or a push endpoint. */
    public function recipientKind(): string
    {
        return match ($this) {
            self::Email => 'email',
            self::Push => 'endpoint',
            default => 'mobile',
        };
    }

    /** Rendered bodies are escaped for the context they land in (TemplateRenderer). */
    public function escaping(): string
    {
        return match ($this) {
            self::Email => 'html',
            self::Ivr => 'speech',
            default => 'text',
        };
    }
}
