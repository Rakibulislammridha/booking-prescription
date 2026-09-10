<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Services;

use App\Domain\Notifications\Contracts\PlatformGatewayDefaults;
use App\Domain\Notifications\Data\GatewayConfig;
use App\Domain\Notifications\Enums\GatewayProvider;
use App\Domain\Notifications\Enums\NotificationChannel;
use App\Domain\SaaS\Support\PlatformSettingsRegistry;

/**
 * The platform's own SMS gateway (`sms.*` platform settings), handed to `GatewayResolver` for a tenant that has
 * no usable gateway row of its own — so a brand-new clinic's booking confirmations go out on day one instead of
 * to the log driver. The credentials are platform settings (the token and password are `secret` keys: encrypted
 * at rest, masked on the console), read at request time; nothing here touches a tenant schema.
 *
 * Credentials are laid out the way the drivers already read them: SSL Wireless wants `api_token` + `sid`, the
 * generic HTTP driver wants `url` plus `api_key`/`token` or `username`/`password`. One token field feeds all
 * three token-shaped names, so an operator fills in the form once whichever provider is chosen.
 */
final class PlatformSmsGateway implements PlatformGatewayDefaults
{
    public function __construct(private readonly PlatformSettings $settings) {}

    public function configFor(NotificationChannel $channel): ?GatewayConfig
    {
        if ($channel !== NotificationChannel::Sms) {
            return null;
        }

        $provider = GatewayProvider::tryFrom((string) $this->settings->get(PlatformSettingsRegistry::SMS_PROVIDER));

        if ($provider === null) {
            return null;
        }

        $token = trim((string) $this->settings->get(PlatformSettingsRegistry::SMS_API_TOKEN));
        $credentials = array_filter([
            'url' => trim((string) $this->settings->get(PlatformSettingsRegistry::SMS_URL)),
            'api_token' => $token,
            'token' => $token,
            'api_key' => $token,
            'sid' => trim((string) $this->settings->get(PlatformSettingsRegistry::SMS_SID)),
            'username' => trim((string) $this->settings->get(PlatformSettingsRegistry::SMS_USERNAME)),
            'password' => trim((string) $this->settings->get(PlatformSettingsRegistry::SMS_PASSWORD)),
        ], fn (string $v) => $v !== '');

        $config = new GatewayConfig(
            channel: NotificationChannel::Sms,
            provider: $provider,
            name: 'platform',
            credentials: $credentials,
            options: [],
            senderId: trim((string) $this->settings->get(PlatformSettingsRegistry::SMS_SENDER_ID)) ?: null,
            gatewayId: null,
        );

        return $config->hasCredentials() ? $config : null;
    }

    /** What the console shows: provider, sender id and whether a usable credential set is stored. */
    /** @return array{provider: string, sender_id: string, configured: bool} */
    public function summary(): array
    {
        return [
            'provider' => (string) $this->settings->get(PlatformSettingsRegistry::SMS_PROVIDER),
            'sender_id' => (string) $this->settings->get(PlatformSettingsRegistry::SMS_SENDER_ID),
            'configured' => $this->configFor(NotificationChannel::Sms) !== null,
        ];
    }
}
