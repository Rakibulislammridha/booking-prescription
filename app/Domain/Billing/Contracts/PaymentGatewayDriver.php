<?php

declare(strict_types=1);

namespace App\Domain\Billing\Contracts;

use App\Domain\Billing\Data\CheckoutRequest;
use App\Domain\Billing\Data\CheckoutSession;
use App\Domain\Billing\Data\GatewayCallback;
use App\Domain\Billing\Data\GatewayRefundResult;
use App\Domain\Billing\Data\GatewayVerification;
use App\Domain\Billing\Enums\PaymentGateway;
use App\Domain\Billing\Exceptions\GatewaySignatureInvalid;
use App\Models\Tenant\Payment;
use Illuminate\Http\Request;

/**
 * One driver per gateway (bKash, Nagad, SSLCommerz — BRIEF §5.I), plus `LogPaymentGateway` for tests and for
 * tenants with no credentials configured.
 *
 * The contract is deliberately split into three steps so that no single inbound HTTP request can move money:
 *
 *   createCheckout()    we freeze OUR amount and OUR merchant reference, then hand the patient to the gateway.
 *   verifyCallback()    an inbound callback/webhook is authenticated (HMAC / hash / shared secret, compared
 *                       with hash_equals) and reduced to identifiers. It carries no amount by construction.
 *   verifyTransaction() a server-to-server call asks the gateway what really happened. Only this amount is
 *                       believed, and it must equal the amount frozen at checkout or nothing is credited.
 *
 * Every method must be safe to call twice: gateways re-deliver webhooks, and patients refresh callback pages.
 */
interface PaymentGatewayDriver
{
    public function gateway(): PaymentGateway;

    /** False when this tenant has no credentials; the caller then refuses the checkout instead of guessing. */
    public function isConfigured(): bool;

    public function createCheckout(CheckoutRequest $request): CheckoutSession;

    /** @throws GatewaySignatureInvalid when the payload is not provably from the gateway */
    public function verifyCallback(Request $request): GatewayCallback;

    public function verifyTransaction(GatewayCallback $callback, Payment $payment): GatewayVerification;

    public function refund(Payment $payment, int $amountPaisa, string $reference): GatewayRefundResult;
}
