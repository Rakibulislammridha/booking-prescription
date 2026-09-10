<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Services;

use App\Domain\Notifications\Contracts\ChannelDriver;
use App\Domain\Notifications\Contracts\DriverFactory;
use App\Domain\Notifications\Contracts\PlatformGatewayDefaults;
use App\Domain\Notifications\Data\GatewayConfig;
use App\Domain\Notifications\Drivers\Ivr\HttpIvrDriver;
use App\Domain\Notifications\Drivers\LogChannelDriver;
use App\Domain\Notifications\Drivers\Mail\MailerDriver;
use App\Domain\Notifications\Drivers\Push\WebPushDriver;
use App\Domain\Notifications\Drivers\Sms\GenericHttpSmsDriver;
use App\Domain\Notifications\Drivers\Sms\SslWirelessSmsDriver;
use App\Domain\Notifications\Drivers\WhatsApp\WhatsAppCloudDriver;
use App\Domain\Notifications\Enums\GatewayProvider;
use App\Domain\Notifications\Enums\NotificationChannel;
use App\Models\Tenant\SmsGatewaySetting;
use Illuminate\Contracts\Mail\Mailer;

/**
 * Tenant credential resolution — the isolation boundary of this module.
 *
 * The ONLY way a driver gets credentials is a `sms_gateway_settings` row of the CURRENT tenant, read through the
 * tenant-scoped connection (the model is a TenantModel, so a bare `sms_gateway_settings` resolves against the
 * tenant's search path and nothing else). Order: the row marked `is_default` for the channel, then `priority`
 * ascending, then id. A tenant with no usable row falls back to the log driver, so a clinic that has not entered
 * credentials still produces a complete outbound ledger instead of exceptions.
 *
 * Nothing here ever reads a credential from config or the environment: `notifications.force_log_driver` only
 * chooses the *log* driver, and the VAPID key pair (platform identity, not a tenant secret) is injected.
 */
final class GatewayResolver implements DriverFactory
{
    /** @var array<string, ChannelDriver> */
    private array $memo = [];

    public function __construct(
        private readonly SegmentCounter $segments,
        private readonly VapidSigner $vapid,
        private readonly WebPushEncryptor $encryptor,
        private readonly Mailer $mailer,
        private readonly bool $forceLogDriver = false,
        private readonly int $timeout = 10,
        private readonly string $mailFromAddress = 'no-reply@example.test',
        private readonly string $mailFromName = 'Clinic',
        private readonly int $pushTtl = 3600,
        private readonly int $pushPruneAfterFailures = 5,
        // The platform's own gateway, inherited by a tenant with no usable row (super console → SaaS binding).
        private readonly ?PlatformGatewayDefaults $platform = null,
    ) {}

    public function for(NotificationChannel $channel): ChannelDriver
    {
        return $this->memo[$channel->value] ??= $this->build($channel);
    }

    public function configFor(NotificationChannel $channel): ?GatewayConfig
    {
        if (! $channel->usesGatewayRow()) {
            return null;
        }

        $row = SmsGatewaySetting::query()->usable($channel)->first();

        if ($row === null) {
            return $this->platform?->configFor($channel);
        }

        $config = GatewayConfig::fromModel($row);

        return $config->hasCredentials() ? $config : $this->platform?->configFor($channel);
    }

    public function fromConfig(GatewayConfig $config): ChannelDriver
    {
        return match ($config->channel) {
            NotificationChannel::Sms => $config->provider === GatewayProvider::SslWireless
                ? new SslWirelessSmsDriver($config, $this->segments, $this->timeout)
                : new GenericHttpSmsDriver($config, $this->segments, $this->timeout),
            NotificationChannel::Whatsapp => $config->provider === GatewayProvider::WhatsappCloud
                ? new WhatsAppCloudDriver($config, $this->timeout)
                : new GenericHttpSmsDriver($config, $this->segments, $this->timeout, NotificationChannel::Whatsapp),
            NotificationChannel::Ivr => new HttpIvrDriver($config, $this->timeout + 5),
            default => new LogChannelDriver($config->channel),
        };
    }

    /** Reset the per-instance memo — the queue worker calls this when tenancy changes under a long-lived binding. */
    public function forget(): void
    {
        $this->memo = [];
    }

    private function build(NotificationChannel $channel): ChannelDriver
    {
        if ($this->forceLogDriver) {
            return new LogChannelDriver($channel);
        }

        if ($channel === NotificationChannel::Email) {
            return new MailerDriver($this->mailer, $this->mailFromAddress, $this->mailFromName);
        }

        if ($channel === NotificationChannel::Push) {
            return $this->vapid->isConfigured()
                ? new WebPushDriver($this->vapid, $this->encryptor, $this->timeout, $this->pushTtl, $this->pushPruneAfterFailures)
                : new LogChannelDriver($channel);
        }

        $config = $this->configFor($channel);

        return $config === null ? new LogChannelDriver($channel) : $this->fromConfig($config);
    }
}
