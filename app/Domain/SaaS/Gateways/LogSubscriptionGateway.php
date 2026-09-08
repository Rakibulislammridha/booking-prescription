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
use App\Models\Central\SubscriptionPayment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The reference driver — the platform's counterpart of `Billing\Gateways\LogPaymentGateway`, and the one the
 * suite runs against.
 *
 * It talks to nobody, but it signs its own callback with the app key so the replay and tamper tests exercise a
 * real `hash_equals` path, and `verifyTransaction()` reports the amount that was FROZEN on the payment row rather
 * than anything the callback said — so even here the callback cannot dictate an amount.
 */
final class LogSubscriptionGateway implements SubscriptionGatewayDriver
{
    public function __construct(private readonly PaymentGateway $gateway = PaymentGateway::Sslcommerz) {}

    public function gateway(): PaymentGateway
    {
        return $this->gateway;
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function createCheckout(SubscriptionCheckoutRequest $request): CheckoutSession
    {
        $txnId = 'PLT'.mb_strtoupper((string) Str::ulid());

        Log::info('saas.gateway.checkout', [
            'gateway' => $this->gateway->value,
            'merchant_ref' => $request->merchantRef,
            'amount_paisa' => $request->amountPaisa,
        ]);

        return new CheckoutSession(
            redirectUrl: $request->callbackUrl.(str_contains($request->callbackUrl, '?') ? '&' : '?').http_build_query([
                'ref' => $request->merchantRef,
                'txn' => $txnId,
                'status' => 'success',
                'sig' => self::sign($request->merchantRef, $txnId, 'success'),
            ]),
            gatewayTxnId: $txnId,
            payload: ['driver' => 'log'],
        );
    }

    public function verifyCallback(Request $request): GatewayCallback
    {
        $ref = (string) $request->input('ref', '');
        $txn = (string) $request->input('txn', '');
        $status = (string) $request->input('status', '');
        $signature = (string) $request->input('sig', '');

        if ($ref === '' || $txn === '' || ! hash_equals(self::sign($ref, $txn, $status), $signature)) {
            throw new GatewaySignatureInvalid(['gateway' => $this->gateway->value]);
        }

        return new GatewayCallback($this->gateway, $ref, $txn, $status, $request->all());
    }

    public function verifyTransaction(GatewayCallback $callback, SubscriptionPayment $payment): GatewayVerification
    {
        if (mb_strtolower($callback->rawStatus) !== 'success') {
            return GatewayVerification::failed($callback->rawStatus, $callback->payload);
        }

        return new GatewayVerification(true, $payment->amount_paisa, $callback->gatewayTxnId, 'BDT', $callback->payload);
    }

    public static function sign(string $ref, string $txn, string $status): string
    {
        return hash_hmac('sha256', $ref.'|'.$txn.'|'.$status, (string) config('app.key'));
    }
}
