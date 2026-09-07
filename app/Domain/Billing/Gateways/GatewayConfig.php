<?php

declare(strict_types=1);

namespace App\Domain\Billing\Gateways;

use App\Domain\Billing\Enums\PaymentGateway;
use App\Domain\Clinic\Services\Settings;
use App\Domain\Clinic\Support\SettingsRegistry;

/**
 * Gateway credentials, per tenant, with a config fallback — and nothing invented.
 *
 * Lookup order for `bkash.app_key`:
 *   1. tenant setting `billing.gateway.bkash.app_key`  (only once the foundation registers the key; the
 *      registry is closed and `Settings::get()` throws on an unknown key, so the guard is `SettingsRegistry::has`)
 *   2. `config('billing.gateways.bkash.app_key')`      (config/billing.php + .env, a foundation-owned file)
 *
 * With neither present the driver reports `isConfigured() === false` and the module degrades gracefully: online
 * payment is simply not offered, and an explicit attempt raises `GatewayNotConfigured` instead of silently
 * accepting a callback.
 */
final class GatewayConfig
{
    public const SETTING_PREFIX = 'billing.gateway.';

    public const CONFIG_PREFIX = 'billing.gateways.';

    public function __construct(private readonly Settings $settings) {}

    public function get(PaymentGateway $gateway, string $key, mixed $default = null): mixed
    {
        $settingKey = self::SETTING_PREFIX.$gateway->value.'.'.$key;

        if (SettingsRegistry::has($settingKey)) {
            $value = $this->settings->get($settingKey);

            if ($value !== null && $value !== '') {
                return $value;
            }
        }

        return config(self::CONFIG_PREFIX.$gateway->value.'.'.$key, $default);
    }

    public function string(PaymentGateway $gateway, string $key): ?string
    {
        $value = $this->get($gateway, $key);

        return is_scalar($value) && (string) $value !== '' ? (string) $value : null;
    }

    /** All the named keys are present and non-empty. */
    public function has(PaymentGateway $gateway, string ...$keys): bool
    {
        foreach ($keys as $key) {
            if ($this->string($gateway, $key) === null) {
                return false;
            }
        }

        return true;
    }

    /** Sandbox unless the tenant/config says `live`. Never default to live. */
    public function isLive(PaymentGateway $gateway): bool
    {
        return $this->string($gateway, 'mode') === 'live';
    }

    public function baseUrl(PaymentGateway $gateway, string $sandbox, string $live): string
    {
        return $this->string($gateway, 'base_url') ?? ($this->isLive($gateway) ? $live : $sandbox);
    }
}
