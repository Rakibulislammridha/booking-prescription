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
use App\Domain\Billing\Exceptions\GatewayNotConfigured;
use App\Domain\Billing\Exceptions\GatewaySignatureInvalid;
use App\Domain\Billing\Services\Paisa;
use App\Models\Tenant\Payment;
use App\Tenancy\Facades\Tenancy;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * bKash Tokenized Checkout (PGW).
 *
 *   token/grant   POST {base}/tokenized/checkout/token/grant     → id_token (validity 3600 s, cached 55 min)
 *   create        POST {base}/tokenized/checkout/create          → paymentID + bkashURL (mode 0011)
 *   execute       POST {base}/tokenized/checkout/execute         → trxID, amount, transactionStatus
 *   query         POST {base}/tokenized/checkout/payment/status  → the same, idempotently
 *
 * bKash does not sign its callback: the browser comes back with `paymentID` and `status` only. That is exactly
 * why nothing is trusted from it — the callback is authenticated by the fact that `paymentID` must match the
 * `gateway_payment_ref` we stored on OUR pending payment row, and the amount is then read from `execute`/
 * `query`, server-to-server. A replayed callback finds the payment already terminal and changes nothing; a
 * tampered `paymentID` matches no pending payment of this tenant and is rejected.
 */
final class BkashGateway implements PaymentGatewayDriver
{
    private const SANDBOX = 'https://tokenized.sandbox.bka.sh/v1.2.0-beta';

    private const LIVE = 'https://tokenized.pay.bka.sh/v1.2.0-beta';

    public function __construct(private readonly GatewayConfig $config) {}

    public function gateway(): PaymentGateway
    {
        return PaymentGateway::Bkash;
    }

    public function isConfigured(): bool
    {
        return $this->config->has($this->gateway(), 'app_key', 'app_secret', 'username', 'password');
    }

    public function createCheckout(CheckoutRequest $request): CheckoutSession
    {
        $this->assertConfigured();

        $response = $this->client()->post($this->url('/tokenized/checkout/create'), [
            'mode' => '0011',                                     // checkout with an agreement-less one-off payment
            'payerReference' => $request->payerMobile ?? $request->merchantRef,
            'callbackURL' => $request->callbackUrl,
            'amount' => $request->amountTaka(),
            'currency' => 'BDT',
            'intent' => 'sale',
            'merchantInvoiceNumber' => mb_substr($request->invoice->number, 0, 255),
        ])->throw()->json();

        $paymentId = is_array($response) ? (string) ($response['paymentID'] ?? '') : '';
        $url = is_array($response) ? (string) ($response['bkashURL'] ?? '') : '';

        if ($paymentId === '' || $url === '') {
            throw new GatewayNotConfigured(['gateway' => $this->gateway()->value]);
        }

        return new CheckoutSession($url, $paymentId, is_array($response) ? $response : []);
    }

    public function verifyCallback(Request $request): GatewayCallback
    {
        $paymentId = trim((string) $request->input('paymentID', ''));

        if ($paymentId === '') {
            throw new GatewaySignatureInvalid(['gateway' => $this->gateway()->value]);
        }

        // `paymentID` IS the shared secret here: it was minted by bKash for our create call and stored on the
        // pending payment row. Nothing else from this request is used.
        return new GatewayCallback($this->gateway(), $paymentId, null, (string) $request->input('status', 'unknown'), $request->all());
    }

    public function verifyTransaction(GatewayCallback $callback, Payment $payment): GatewayVerification
    {
        $this->assertConfigured();

        if ($callback->isCancelled()) {
            return GatewayVerification::failed($callback->rawStatus, $callback->payload);
        }

        $paymentId = $callback->merchantRef ?? '';
        $body = $this->client()->post($this->url('/tokenized/checkout/execute'), ['paymentID' => $paymentId])->json();

        // A re-delivered callback finds the payment already executed; `query` then returns the same document.
        if (! is_array($body) || ! isset($body['transactionStatus'])) {
            $body = $this->client()->post($this->url('/tokenized/checkout/payment/status'), ['paymentID' => $paymentId])->json();
        }

        if (! is_array($body)) {
            return GatewayVerification::failed('bkash.no_response');
        }

        $status = (string) ($body['transactionStatus'] ?? ($body['statusMessage'] ?? 'unknown'));

        if (mb_strtolower($status) !== 'completed') {
            return GatewayVerification::failed($status, $body);
        }

        return new GatewayVerification(
            succeeded: true,
            amountPaisa: Paisa::fromDecimal((string) ($body['amount'] ?? '0')),
            gatewayTxnId: (string) ($body['trxID'] ?? $paymentId),
            currency: (string) ($body['currency'] ?? 'BDT'),
            payload: $body,
        );
    }

    public function refund(Payment $payment, int $amountPaisa, string $reference): GatewayRefundResult
    {
        $this->assertConfigured();

        $body = $this->client()->post($this->url('/tokenized/checkout/payment/refund'), [
            'paymentID' => (string) $payment->gateway_payment_ref,
            'trxID' => (string) $payment->gateway_txn_id,
            'amount' => Paisa::toDecimal($amountPaisa),
            'sku' => mb_substr($reference, 0, 255),
            'reason' => mb_substr($reference, 0, 255),
        ])->json();

        $ok = is_array($body) && mb_strtolower((string) ($body['transactionStatus'] ?? '')) === 'completed';

        return new GatewayRefundResult($ok, is_array($body) ? (string) ($body['refundTrxID'] ?? '') : null, is_array($body) ? $body : [], $ok ? null : 'bkash.refund_failed');
    }

    private function client(): PendingRequest
    {
        return Http::asJson()
            ->timeout(30)
            ->withHeaders([
                'Authorization' => $this->token(),
                'X-APP-Key' => (string) $this->config->string($this->gateway(), 'app_key'),
            ]);
    }

    /** id_token lives an hour; cache it just under that, per tenant, so a burst of bookings grants once. */
    private function token(): string
    {
        $key = 'billing:bkash:token:'.(Tenancy::id() ?? 0);

        return (string) Cache::remember($key, 3300, function (): string {
            $body = Http::asJson()->timeout(30)->withHeaders([
                'username' => (string) $this->config->string($this->gateway(), 'username'),
                'password' => (string) $this->config->string($this->gateway(), 'password'),
            ])->post($this->url('/tokenized/checkout/token/grant'), [
                'app_key' => (string) $this->config->string($this->gateway(), 'app_key'),
                'app_secret' => (string) $this->config->string($this->gateway(), 'app_secret'),
            ])->throw()->json();

            return is_array($body) ? (string) ($body['id_token'] ?? '') : '';
        });
    }

    private function url(string $path): string
    {
        return rtrim($this->config->baseUrl($this->gateway(), self::SANDBOX, self::LIVE), '/').$path;
    }

    private function assertConfigured(): void
    {
        if (! $this->isConfigured()) {
            throw new GatewayNotConfigured(['gateway' => $this->gateway()->value]);
        }
    }
}
