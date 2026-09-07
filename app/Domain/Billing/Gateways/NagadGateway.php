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
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * Nagad Merchant Checkout (DFS).
 *
 *   initialize  POST {base}/api/dfs/check-out/initialize/{merchantId}/{orderId}
 *               body { accountNumber, dateTime, sensitiveData (RSA-OAEP, merchant public key), signature
 *               (SHA256withRSA over the same JSON, merchant private key) } → { sensitiveData, signature }
 *   complete    POST {base}/api/dfs/check-out/complete/{paymentReferenceId} → { callBackUrl }
 *   verify      GET  {base}/api/dfs/verify/payment/{payment_ref_id} → { status: 'Success', amount, orderId,
 *               issuerPaymentRefNo }
 *
 * The redirect back carries `payment_ref_id`, `order_id` and `status`. It is not signed, so — as with bKash —
 * the reference is only an identifier: it must match the `gateway_payment_ref` we stored, and the amount comes
 * from the `verify` call. `merchantId` and the RSA keys are read from settings/config and never guessed.
 */
final class NagadGateway implements PaymentGatewayDriver
{
    private const SANDBOX = 'https://sandbox.mynagad.com:10060';

    private const LIVE = 'https://api.mynagad.com';

    public function __construct(private readonly GatewayConfig $config) {}

    public function gateway(): PaymentGateway
    {
        return PaymentGateway::Nagad;
    }

    public function isConfigured(): bool
    {
        return $this->config->has($this->gateway(), 'merchant_id', 'merchant_number', 'public_key', 'private_key');
    }

    public function createCheckout(CheckoutRequest $request): CheckoutSession
    {
        $this->assertConfigured();

        $merchantId = (string) $this->config->string($this->gateway(), 'merchant_id');
        $orderId = $request->merchantRef;
        $dateTime = now()->setTimezone('Asia/Dhaka')->format('YmdHis');

        $sensitive = [
            'merchantId' => $merchantId,
            'datetime' => $dateTime,
            'orderId' => $orderId,
            'challenge' => bin2hex(random_bytes(20)),
        ];

        $initialize = Http::asJson()->timeout(30)->withHeaders($this->headers())->post(
            $this->url("/api/dfs/check-out/initialize/{$merchantId}/{$orderId}"),
            [
                'accountNumber' => (string) $this->config->string($this->gateway(), 'merchant_number'),
                'dateTime' => $dateTime,
                'sensitiveData' => $this->encrypt($sensitive),
                'signature' => $this->sign($sensitive),
            ],
        )->throw()->json();

        $decoded = $this->decryptSensitive(is_array($initialize) ? $initialize : []);
        $paymentReferenceId = (string) ($decoded['paymentReferenceId'] ?? '');
        $challenge = (string) ($decoded['challenge'] ?? '');

        if ($paymentReferenceId === '') {
            throw new GatewayNotConfigured(['gateway' => $this->gateway()->value]);
        }

        $order = [
            'merchantId' => $merchantId,
            'orderId' => $orderId,
            'currencyCode' => '050',                                       // ISO 4217 numeric for BDT
            'amount' => $request->amountTaka(),
            'challenge' => $challenge,
        ];

        $complete = Http::asJson()->timeout(30)->withHeaders($this->headers())->post(
            $this->url("/api/dfs/check-out/complete/{$paymentReferenceId}"),
            [
                'sensitiveData' => $this->encrypt($order),
                'signature' => $this->sign($order),
                'merchantCallbackURL' => $request->callbackUrl,
                'additionalMerchantInfo' => (object) ['invoice' => $request->invoice->number],
            ],
        )->throw()->json();

        $url = is_array($complete) ? (string) ($complete['callBackUrl'] ?? '') : '';

        if ($url === '') {
            throw new GatewayNotConfigured(['gateway' => $this->gateway()->value]);
        }

        return new CheckoutSession($url, $paymentReferenceId, is_array($complete) ? $complete : []);
    }

    public function verifyCallback(Request $request): GatewayCallback
    {
        $reference = trim((string) $request->input('payment_ref_id', ''));

        if ($reference === '') {
            throw new GatewaySignatureInvalid(['gateway' => $this->gateway()->value]);
        }

        return new GatewayCallback($this->gateway(), $reference, null, (string) $request->input('status', 'unknown'), $request->all());
    }

    public function verifyTransaction(GatewayCallback $callback, Payment $payment): GatewayVerification
    {
        $this->assertConfigured();

        if ($callback->isCancelled()) {
            return GatewayVerification::failed($callback->rawStatus, $callback->payload);
        }

        $body = Http::asJson()->timeout(30)->withHeaders($this->headers())
            ->get($this->url('/api/dfs/verify/payment/'.rawurlencode((string) $callback->merchantRef)))
            ->json();

        if (! is_array($body)) {
            return GatewayVerification::failed('nagad.no_response');
        }

        $status = (string) ($body['status'] ?? 'unknown');

        if (mb_strtolower($status) !== 'success') {
            return GatewayVerification::failed($status, $body);
        }

        return new GatewayVerification(
            succeeded: true,
            amountPaisa: Paisa::fromDecimal((string) ($body['amount'] ?? '0')),
            gatewayTxnId: (string) ($body['issuerPaymentRefNo'] ?? ($body['paymentRefId'] ?? $callback->merchantRef)),
            currency: 'BDT',
            payload: $body,
        );
    }

    public function refund(Payment $payment, int $amountPaisa, string $reference): GatewayRefundResult
    {
        // Nagad refunds are a merchant-portal operation for most OPD merchants: report honestly rather than
        // pretend, so the desk hands the cash back and the refund row records how.
        return new GatewayRefundResult(false, null, ['reference' => $reference], 'nagad.refund_manual');
    }

    /** @return array<string, string> */
    private function headers(): array
    {
        return [
            'X-KM-Api-Version' => 'v-0.2.0',
            'X-KM-IP-V4' => request()->ip() ?? '127.0.0.1',
            'X-KM-Client-Type' => 'PC_WEB',
        ];
    }

    /** @param array<string, mixed> $data */
    private function encrypt(array $data): string
    {
        $key = openssl_pkey_get_public($this->pem('public_key', 'PUBLIC KEY'));

        if ($key === false) {
            throw new GatewayNotConfigured(['gateway' => $this->gateway()->value]);
        }

        openssl_public_encrypt((string) json_encode($data), $encrypted, $key, OPENSSL_PKCS1_PADDING);

        return base64_encode((string) $encrypted);
    }

    /** @param array<string, mixed> $data */
    private function sign(array $data): string
    {
        $key = openssl_pkey_get_private($this->pem('private_key', 'PRIVATE KEY'));

        if ($key === false) {
            throw new GatewayNotConfigured(['gateway' => $this->gateway()->value]);
        }

        openssl_sign((string) json_encode($data), $signature, $key, OPENSSL_ALGO_SHA256);

        return base64_encode((string) $signature);
    }

    /**
     * @param  array<string, mixed>  $response
     * @return array<string, mixed>
     */
    private function decryptSensitive(array $response): array
    {
        $sensitive = (string) ($response['sensitiveData'] ?? '');

        if ($sensitive === '') {
            return [];
        }

        $key = openssl_pkey_get_private($this->pem('private_key', 'PRIVATE KEY'));

        if ($key === false || ! openssl_private_decrypt(base64_decode($sensitive, true) ?: '', $plain, $key, OPENSSL_PKCS1_PADDING)) {
            return [];
        }

        $decoded = json_decode((string) $plain, true);

        return is_array($decoded) ? $decoded : [];
    }

    /** Credentials may be stored bare (no PEM armour) — normalise without ever hard-coding a key. */
    private function pem(string $key, string $label): string
    {
        $value = trim((string) $this->config->string($this->gateway(), $key));

        if (str_contains($value, 'BEGIN')) {
            return $value;
        }

        return "-----BEGIN {$label}-----\n".chunk_split($value, 64, "\n")."-----END {$label}-----\n";
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
