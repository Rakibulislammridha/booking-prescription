<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Gateways;

use App\Domain\Billing\Enums\PaymentGateway;
use App\Domain\SaaS\Contracts\SubscriptionGatewayDriver;
use App\Domain\SaaS\Exceptions\SubscriptionGatewayNotConfigured;

/**
 * Which driver collects a platform invoice.
 *
 * Credentials come from `config('billing.gateways.*')` — the PLATFORM-level fallback that config file already
 * documents — never from a tenant's own `billing.gateway.*` settings: a clinic's merchant account collects from
 * its patients, not from itself on our behalf.
 *
 * With no credentials configured the platform does not pretend: the gateway is simply not offered and the tenant
 * pays by bank transfer or bKash merchant, which a super admin records against the invoice (`bank_transfer`,
 * `manual` — SCHEMA §2.6 lists exactly those methods for exactly this reason). That is how platform collection
 * actually works in this market today, and it is why `available()` may legitimately be empty.
 */
final class SubscriptionGatewayManager
{
    /** @var array<string, SubscriptionGatewayDriver> */
    private array $drivers = [];

    public function driver(PaymentGateway $gateway): SubscriptionGatewayDriver
    {
        return $this->drivers[$gateway->value] ??= $this->make($gateway);
    }

    /** @throws SubscriptionGatewayNotConfigured */
    public function configuredDriver(PaymentGateway $gateway): SubscriptionGatewayDriver
    {
        $driver = $this->driver($gateway);

        if (! $driver->isConfigured()) {
            throw new SubscriptionGatewayNotConfigured($gateway);
        }

        return $driver;
    }

    /** @return array<int, PaymentGateway> */
    public function available(): array
    {
        return array_values(array_filter(PaymentGateway::cases(), fn (PaymentGateway $g) => $this->driver($g)->isConfigured()));
    }

    public function forget(): void
    {
        $this->drivers = [];
    }

    private function make(PaymentGateway $gateway): SubscriptionGatewayDriver
    {
        if (config('billing.gateways.driver') === 'log') {
            return new LogSubscriptionGateway($gateway);
        }

        return new UnconfiguredSubscriptionGateway($gateway);
    }
}
