<?php

declare(strict_types=1);

namespace App\Domain\Billing\Gateways;

use App\Domain\Billing\Contracts\PaymentGatewayDriver;
use App\Domain\Billing\Enums\PaymentGateway;
use App\Domain\Billing\Exceptions\GatewayNotConfigured;

/**
 * Resolves the driver for a gateway, per tenant. There is no implicit fallback to the log driver for a live
 * checkout: an unconfigured gateway raises `GatewayNotConfigured` rather than quietly accepting money it cannot
 * verify. `config('billing.gateways.driver') === 'log'` (tests, local) swaps every driver for `LogPaymentGateway`.
 */
final class GatewayManager
{
    /** @var array<string, PaymentGatewayDriver> */
    private array $drivers = [];

    public function __construct(private readonly GatewayConfig $config) {}

    public function driver(PaymentGateway $gateway): PaymentGatewayDriver
    {
        return $this->drivers[$gateway->value] ??= $this->make($gateway);
    }

    /** @throws GatewayNotConfigured */
    public function configuredDriver(PaymentGateway $gateway): PaymentGatewayDriver
    {
        $driver = $this->driver($gateway);

        if (! $driver->isConfigured()) {
            throw new GatewayNotConfigured(['gateway' => $gateway->value]);
        }

        return $driver;
    }

    /** @return array<int, PaymentGateway> the gateways this tenant can actually take money through */
    public function available(): array
    {
        return array_values(array_filter(PaymentGateway::cases(), fn (PaymentGateway $g) => $this->driver($g)->isConfigured()));
    }

    public function isAnyConfigured(): bool
    {
        return $this->available() !== [];
    }

    /** Octane/queue safety: drivers hold per-tenant credentials, so they never outlive a unit of work. */
    public function forget(): void
    {
        $this->drivers = [];
    }

    private function make(PaymentGateway $gateway): PaymentGatewayDriver
    {
        if (config('billing.gateways.driver') === 'log') {
            return new LogPaymentGateway($gateway);
        }

        return match ($gateway) {
            PaymentGateway::Bkash => new BkashGateway($this->config),
            PaymentGateway::Nagad => new NagadGateway($this->config),
            PaymentGateway::Sslcommerz => new SslcommerzGateway($this->config),
        };
    }
}
