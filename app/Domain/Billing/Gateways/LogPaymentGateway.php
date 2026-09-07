<?php

declare(strict_types=1);

namespace App\Domain\Billing\Gateways;

use App\Domain\Billing\Contracts\PaymentGatewayDriver;
use App\Domain\Billing\Data\CheckoutRequest;
use App\Domain\Billing\Data\CheckoutSession;
use App\Domain\Billing\Data\GatewayCallback;
use App\Domain\Billing\Data\GatewayRefundResult;
use App\Domain\Billing\Data\GatewayVerification;
use App\Domain\Billing\Enums\PaymentGateway;
use App\Domain\Billing\Exceptions\GatewaySignatureInvalid;
use App\Models\Tenant\Payment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * The null/log driver: used in tests and wherever no credentials are configured. It talks to nobody, signs its
 * own callbacks with the app key so the replay/tamper tests exercise a real `hash_equals` path, and reports the
 * amount that was FROZEN on the payment row — so even here the callback cannot dictate an amount.
 *
 * It is never selected implicitly for a live checkout: `GatewayManager` only hands it out when the driver is
 * explicitly asked for, or when `billing.gateways.driver` is `log`.
 */
final class LogPaymentGateway implements PaymentGatewayDriver
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

    public function createCheckout(CheckoutRequest $request): CheckoutSession
    {
        $txnId = 'LOG'.mb_strtoupper((string) Str::ulid());

        Log::info('billing.gateway.checkout', [
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

    /** The truth is what we froze; a log driver has no upstream to ask. */
    public function verifyTransaction(GatewayCallback $callback, Payment $payment): GatewayVerification
    {
        if (mb_strtolower($callback->rawStatus) !== 'success') {
            return GatewayVerification::failed($callback->rawStatus, $callback->payload);
        }

        return new GatewayVerification(true, $payment->amount_paisa, $callback->gatewayTxnId, 'BDT', $callback->payload);
    }

    public function refund(Payment $payment, int $amountPaisa, string $reference): GatewayRefundResult
    {
        Log::info('billing.gateway.refund', ['gateway' => $this->gateway->value, 'payment' => $payment->public_id, 'amount_paisa' => $amountPaisa]);

        return new GatewayRefundResult(true, 'LOGRF'.mb_strtoupper((string) Str::ulid()), ['driver' => 'log', 'reference' => $reference]);
    }

    /** HMAC over the identifiers with the app key — enough to make signature tests meaningful. */
    public static function sign(string $ref, string $txn, string $status): string
    {
        return hash_hmac('sha256', $ref.'|'.$txn.'|'.$status, (string) config('app.key'));
    }
}
