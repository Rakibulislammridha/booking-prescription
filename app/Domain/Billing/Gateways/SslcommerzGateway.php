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
 * SSLCommerz hosted checkout.
 *
 *   session   POST {base}/gwprocess/v4/api.php  (form) → { status: 'SUCCESS', GatewayPageURL, sessionkey }
 *   IPN/redirect  POST our url with tran_id, val_id, amount, status, verify_sign, verify_key
 *   validate  GET  {base}/validator/api/validationserverAPI.php?val_id=&store_id=&store_passwd=&format=json
 *             → { status: 'VALID'|'VALIDATED', amount, currency, tran_id, bank_tran_id }
 *
 * Unlike the two wallets, SSLCommerz DOES sign its IPN: `verify_sign` is the MD5 of the `verify_key` fields in
 * the order given, each as `field=value`, joined with `&`, with `store_passwd` replaced by its MD5. That check
 * runs first (`hash_equals`), and only then is the amount fetched from the validation API — the posted `amount`
 * is never used. A replayed IPN carries the same `val_id`; the payment is already terminal and nothing moves.
 */
final class SslcommerzGateway implements PaymentGatewayDriver
{
    private const SANDBOX = 'https://sandbox.sslcommerz.com';

    private const LIVE = 'https://securepay.sslcommerz.com';

    public function __construct(private readonly GatewayConfig $config) {}

    public function gateway(): PaymentGateway
    {
        return PaymentGateway::Sslcommerz;
    }

    public function isConfigured(): bool
    {
        return $this->config->has($this->gateway(), 'store_id', 'store_password');
    }

    public function createCheckout(CheckoutRequest $request): CheckoutSession
    {
        $this->assertConfigured();

        $body = Http::asForm()->timeout(30)->post($this->url('/gwprocess/v4/api.php'), [
            'store_id' => (string) $this->config->string($this->gateway(), 'store_id'),
            'store_passwd' => (string) $this->config->string($this->gateway(), 'store_password'),
            'total_amount' => $request->amountTaka(),
            'currency' => 'BDT',
            'tran_id' => $request->merchantRef,
            'success_url' => $request->callbackUrl,
            'fail_url' => $request->cancelUrl,
            'cancel_url' => $request->cancelUrl,
            'ipn_url' => $request->callbackUrl,
            'cus_name' => mb_substr($request->invoice->patient->name ?? 'Patient', 0, 100),
            'cus_email' => 'noreply@invalid.local',
            'cus_phone' => $request->payerMobile ?? '01700000000',
            'cus_add1' => 'N/A',
            'cus_city' => 'Dhaka',
            'cus_country' => 'Bangladesh',
            'shipping_method' => 'NO',
            'product_name' => mb_substr($request->invoice->number, 0, 100),
            'product_category' => 'Healthcare',
            'product_profile' => 'non-physical-goods',
            'value_a' => $request->invoice->public_id,
        ])->throw()->json();

        $url = is_array($body) ? (string) ($body['GatewayPageURL'] ?? '') : '';

        if ($url === '') {
            throw new GatewayNotConfigured(['gateway' => $this->gateway()->value]);
        }

        return new CheckoutSession($url, is_array($body) ? (string) ($body['sessionkey'] ?? '') : null, is_array($body) ? $body : []);
    }

    public function verifyCallback(Request $request): GatewayCallback
    {
        $this->assertConfigured();

        /** @var array<string, mixed> $data */
        $data = $request->all();
        $signature = (string) ($data['verify_sign'] ?? '');
        $keys = (string) ($data['verify_key'] ?? '');
        $tranId = trim((string) ($data['tran_id'] ?? ''));

        if ($tranId === '' || $signature === '' || $keys === '') {
            throw new GatewaySignatureInvalid(['gateway' => $this->gateway()->value]);
        }

        if (! hash_equals($this->expectedSignature($data, $keys), mb_strtolower($signature))) {
            throw new GatewaySignatureInvalid(['gateway' => $this->gateway()->value]);
        }

        return new GatewayCallback(
            $this->gateway(),
            $tranId,
            isset($data['val_id']) ? (string) $data['val_id'] : null,
            (string) ($data['status'] ?? 'unknown'),
            $data,
        );
    }

    public function verifyTransaction(GatewayCallback $callback, Payment $payment): GatewayVerification
    {
        $this->assertConfigured();

        $valId = $callback->gatewayTxnId ?? '';

        if ($valId === '' || $callback->isCancelled()) {
            return GatewayVerification::failed($callback->rawStatus, $callback->payload);
        }

        $body = Http::timeout(30)->get($this->url('/validator/api/validationserverAPI.php'), [
            'val_id' => $valId,
            'store_id' => (string) $this->config->string($this->gateway(), 'store_id'),
            'store_passwd' => (string) $this->config->string($this->gateway(), 'store_password'),
            'format' => 'json',
        ])->json();

        if (! is_array($body)) {
            return GatewayVerification::failed('sslcommerz.no_response');
        }

        $status = mb_strtoupper((string) ($body['status'] ?? 'UNKNOWN'));

        if (! in_array($status, ['VALID', 'VALIDATED'], true)) {
            return GatewayVerification::failed($status, $body);
        }

        // The transaction must be the one we started; a valid val_id for someone else's order is not ours.
        if ((string) ($body['tran_id'] ?? '') !== (string) $payment->gateway_payment_ref) {
            return GatewayVerification::failed('sslcommerz.tran_id_mismatch', $body);
        }

        return new GatewayVerification(
            succeeded: true,
            amountPaisa: Paisa::fromDecimal((string) ($body['amount'] ?? '0')),
            gatewayTxnId: (string) ($body['bank_tran_id'] ?? $valId),
            currency: (string) ($body['currency'] ?? 'BDT'),
            payload: $body,
        );
    }

    public function refund(Payment $payment, int $amountPaisa, string $reference): GatewayRefundResult
    {
        $this->assertConfigured();

        $body = Http::timeout(30)->get($this->url('/validator/api/merchantTransIDvalidationAPI.php'), [
            'bank_tran_id' => (string) $payment->gateway_txn_id,
            'store_id' => (string) $this->config->string($this->gateway(), 'store_id'),
            'store_passwd' => (string) $this->config->string($this->gateway(), 'store_password'),
            'refund_amount' => Paisa::toDecimal($amountPaisa),
            'refund_remarks' => mb_substr($reference, 0, 255),
            'refe_id' => mb_substr($reference, 0, 64),
            'format' => 'json',
        ])->json();

        $ok = is_array($body) && in_array(mb_strtolower((string) ($body['status'] ?? '')), ['success', 'processing'], true);

        return new GatewayRefundResult($ok, is_array($body) ? (string) ($body['refund_ref_id'] ?? '') : null, is_array($body) ? $body : [], $ok ? null : 'sslcommerz.refund_failed');
    }

    /**
     * `verify_sign` = md5 of "field=value&…" over the fields named in `verify_key`, in that order, with
     * `store_passwd` substituted by md5(store_passwd) (SSLCommerz IPN documentation).
     *
     * @param  array<string, mixed>  $data
     */
    private function expectedSignature(array $data, string $verifyKey): string
    {
        $parts = [];

        foreach (explode(',', $verifyKey) as $field) {
            $field = trim($field);

            if ($field === '') {
                continue;
            }

            $value = $field === 'store_passwd'
                ? md5((string) $this->config->string($this->gateway(), 'store_password'))
                : (string) ($data[$field] ?? '');

            $parts[] = $field.'='.$value;
        }

        return md5(implode('&', $parts));
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
