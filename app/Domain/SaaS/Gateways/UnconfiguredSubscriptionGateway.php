<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Gateways;

use App\Domain\Billing\Data\CheckoutSession;
use App\Domain\Billing\Data\GatewayCallback;
use App\Domain\Billing\Data\GatewayVerification;
use App\Domain\Billing\Enums\PaymentGateway;
use App\Domain\Billing\Exceptions\GatewaySignatureInvalid;
use App\Domain\SaaS\Contracts\SubscriptionGatewayDriver;
use App\Domain\SaaS\Data\SubscriptionCheckoutRequest;
use App\Domain\SaaS\Exceptions\SubscriptionGatewayNotConfigured;
use App\Models\Central\SubscriptionPayment;
use Illuminate\Http\Request;

/**
 * The honest "no" for a gateway the platform has no merchant account with. Every method refuses rather than
 * degrading to something that looks like it worked — a checkout nobody charged is worse than no checkout, and a
 * callback nobody can verify must never credit an invoice.
 */
final class UnconfiguredSubscriptionGateway implements SubscriptionGatewayDriver
{
    public function __construct(private readonly PaymentGateway $gatewayValue) {}

    public function gateway(): PaymentGateway
    {
        return $this->gatewayValue;
    }

    public function isConfigured(): bool
    {
        return false;
    }

    public function createCheckout(SubscriptionCheckoutRequest $request): CheckoutSession
    {
        throw new SubscriptionGatewayNotConfigured($this->gatewayValue);
    }

    public function verifyCallback(Request $request): GatewayCallback
    {
        throw new GatewaySignatureInvalid(['gateway' => $this->gatewayValue->value]);
    }

    public function verifyTransaction(GatewayCallback $callback, SubscriptionPayment $payment): GatewayVerification
    {
        return GatewayVerification::failed('gateway_not_configured');
    }
}
