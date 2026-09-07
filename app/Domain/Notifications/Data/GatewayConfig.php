<?php

declare(strict_types=1);

namespace App\Domain\Notifications\Data;

use App\Domain\Notifications\Enums\GatewayProvider;
use App\Domain\Notifications\Enums\NotificationChannel;
use App\Models\Tenant\SmsGatewaySetting;

/**
 * A resolved gateway: the tenant's `sms_gateway_settings` row flattened into a value object, or a config-derived
 * fallback. Drivers are constructed with one of these and never read a model, a config key or an env var — that is
 * what makes tenant A's credentials structurally unable to send tenant B's message.
 */
final readonly class GatewayConfig
{
    /**
     * @param  array<string, mixed>  $credentials
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        public NotificationChannel $channel,
        public GatewayProvider $provider,
        public string $name,
        public array $credentials = [],
        public array $options = [],
        public ?string $senderId = null,
        public ?int $gatewayId = null,
    ) {}

    public static function fromModel(SmsGatewaySetting $row): self
    {
        return new self(
            channel: $row->channel,
            provider: $row->provider,
            name: $row->name,
            credentials: $row->credentials,
            options: $row->options,
            senderId: $row->sender_id,
            gatewayId: $row->id,
        );
    }

    public function credential(string $key, ?string $default = null): ?string
    {
        $value = $this->credentials[$key] ?? null;

        return is_scalar($value) && (string) $value !== '' ? (string) $value : $default;
    }

    public function option(string $key, mixed $default = null): mixed
    {
        return $this->options[$key] ?? $default;
    }

    /** Send Bangla as UCS-2 unless the tenant has explicitly turned Unicode off for this gateway. */
    public function unicodeEnabled(): bool
    {
        return (bool) $this->option('unicode', true);
    }

    public function rateLimitPerSecond(): ?int
    {
        $limit = $this->option('rate_limit_per_sec');

        return is_numeric($limit) && (int) $limit > 0 ? (int) $limit : null;
    }

    /** True when the row carries enough to talk to the provider; otherwise the module falls back to the log driver. */
    public function hasCredentials(): bool
    {
        return array_filter($this->credentials, fn ($v) => is_scalar($v) && (string) $v !== '') !== [];
    }
}
