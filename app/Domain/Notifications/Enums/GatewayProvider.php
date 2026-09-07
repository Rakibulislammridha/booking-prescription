<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Enums;

/** `sms_gateway_settings.provider` (SCHEMA §3.6). Each case maps to exactly one driver class (DriverRegistry). */
enum GatewayProvider: string
{
    case SslWireless = 'ssl_wireless';
    case BulkSmsBd = 'bulksmsbd';
    case Grameenphone = 'grameenphone';
    case Banglalink = 'banglalink';
    case Robi = 'robi';
    case Infobip = 'infobip';
    case Twilio = 'twilio';
    case WhatsappCloud = 'whatsapp_cloud';
    case CustomHttp = 'custom_http';

    /** @return array<int, string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }

    /** @return array<int, self> providers offered for a channel in the panel's gateway form */
    public static function forChannel(NotificationChannel $channel): array
    {
        return match ($channel) {
            NotificationChannel::Whatsapp => [self::WhatsappCloud, self::Infobip, self::Twilio, self::CustomHttp],
            NotificationChannel::Ivr => [self::Twilio, self::Infobip, self::Banglalink, self::Robi, self::CustomHttp],
            default => [self::SslWireless, self::BulkSmsBd, self::Grameenphone, self::Banglalink, self::Robi, self::Infobip, self::Twilio, self::CustomHttp],
        };
    }
}
