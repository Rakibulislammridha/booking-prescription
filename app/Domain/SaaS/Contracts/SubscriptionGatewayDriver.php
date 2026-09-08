<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Contracts;

use App\Domain\Billing\Data\CheckoutSession;
use App\Domain\Billing\Data\GatewayCallback;
use App\Domain\Billing\Data\GatewayVerification;
use App\Domain\Billing\Enums\PaymentGateway;
use App\Domain\Billing\Exceptions\GatewaySignatureInvalid;
use App\Domain\SaaS\Data\SubscriptionCheckoutRequest;
use App\Models\Central\SubscriptionPayment;
use Illuminate\Http\Request;

/**
 * The platform's own collection, on EXACTLY the contract Billing established for clinic payments
 * (`App\Domain\Billing\Contracts\PaymentGatewayDriver`) — same three steps, same reasoning, same DTOs
 * (`CheckoutSession`, `GatewayCallback`, `GatewayVerification`, `PaymentGateway` are Billing's classes, reused,
 * not copied):
 *
 *   createCheckout()     we freeze OUR amount and OUR reference on a pending `subscription_payments` row, then
 *                        hand the browser to the gateway.
 *   verifyCallback()     the inbound return is authenticated with hash_equals and reduced to identifiers. By
 *                        construction it carries no amount, so a tampered query string cannot say "paid ৳1".
 *   verifyTransaction()  a server-to-server question decides what really happened, and its amount must equal the
 *                        amount frozen at checkout or nothing is credited.
 *
 * Why a separate interface rather than Billing's own: `Billing\Data\CheckoutRequest` and its
 * `verifyTransaction()` are typed against `App\Models\Tenant\Payment` and a tenant `Invoice`. Platform invoices
 * are `public` rows that must be payable while the tenant is SUSPENDED — i.e. with no tenancy initialised at all —
 * so the models differ even though nothing else does.
 */
interface SubscriptionGatewayDriver
{
    public function gateway(): PaymentGateway;

    /** False when the platform has no credentials; the caller refuses the checkout instead of guessing. */
    public function isConfigured(): bool;

    public function createCheckout(SubscriptionCheckoutRequest $request): CheckoutSession;

    /** @throws GatewaySignatureInvalid when the payload is not provably from the gateway */
    public function verifyCallback(Request $request): GatewayCallback;

    public function verifyTransaction(GatewayCallback $callback, SubscriptionPayment $payment): GatewayVerification;
}
