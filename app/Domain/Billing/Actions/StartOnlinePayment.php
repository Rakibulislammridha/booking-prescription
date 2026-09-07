<?php

declare(strict_types=1);

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\Data\CheckoutRequest;
use App\Domain\Billing\Data\CheckoutSession;
use App\Domain\Billing\Enums\PaymentGateway;
use App\Domain\Billing\Enums\PaymentTxnStatus;
use App\Domain\Billing\Exceptions\InvoiceNotPayable;
use App\Domain\Billing\Gateways\GatewayManager;
use App\Domain\Shared\Actor;
use App\Models\Tenant\Invoice;
use App\Models\Tenant\Payment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Begin a bKash / Nagad / SSLCommerz checkout.
 *
 * The critical ordering: a `pending` payment row carrying OUR amount and OUR merchant reference is written
 * FIRST, inside the invoice lock, and only then is the gateway asked for a redirect URL. Everything that comes
 * back later is matched against that frozen row, so no callback can invent an amount, and a patient who taps
 * "pay" twice reuses the same pending attempt instead of creating a second charge.
 */
final class StartOnlinePayment
{
    public function __construct(private readonly GatewayManager $gateways) {}

    /** @return array{payment: Payment, session: CheckoutSession} */
    public function handle(Invoice $invoice, PaymentGateway $gateway, Actor $actor, string $callbackUrl, string $cancelUrl, ?string $payerMobile = null): array
    {
        $driver = $this->gateways->configuredDriver($gateway);

        [$payment, $locked] = DB::transaction(function () use ($invoice, $gateway, $actor): array {
            /** @var Invoice $locked */
            $locked = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            if (! $locked->status->isPayable() || $locked->due_paisa <= 0) {
                throw new InvoiceNotPayable(['status' => $locked->status->value]);
            }

            $pending = Payment::query()
                ->where('invoice_id', $locked->id)
                ->where('gateway', $gateway->value)
                ->where('status', PaymentTxnStatus::Pending->value)
                ->where('amount_paisa', $locked->due_paisa)
                ->first();

            if ($pending !== null) {
                return [$pending, $locked];
            }

            $reference = self::reference($locked);

            $payment = new Payment;
            $payment->forceFill([
                'invoice_id' => $locked->id,
                'patient_id' => $locked->patient_id,
                'method' => $gateway->method(),
                'status' => PaymentTxnStatus::Pending,
                'amount_paisa' => $locked->due_paisa,
                'gateway' => $gateway,
                'gateway_payment_ref' => $reference,
                'idempotency_key' => 'gw:'.$gateway->value.':'.$reference,
                'received_by_user_id' => $actor->userId,
            ])->save();

            return [$payment, $locked];
        });

        $session = $driver->createCheckout(new CheckoutRequest(
            invoice: $locked->load('patient'),
            amountPaisa: $payment->amount_paisa,
            merchantRef: (string) $payment->gateway_payment_ref,
            callbackUrl: $callbackUrl,
            cancelUrl: $cancelUrl,
            payerMobile: $payerMobile,
        ));

        // bKash and Nagad mint their own reference at create time; that is the handle their callback returns.
        if ($session->gatewayTxnId !== null && $session->gatewayTxnId !== '' && $gateway !== PaymentGateway::Sslcommerz) {
            $payment->forceFill(['gateway_payment_ref' => $session->gatewayTxnId, 'gateway_payload' => $session->payload])->save();
        } elseif ($session->payload !== []) {
            $payment->forceFill(['gateway_payload' => $session->payload])->save();
        }

        return ['payment' => $payment->refresh(), 'session' => $session];
    }

    /** Short, unique, and safe in a URL — gateways cap `tran_id` around 30 characters. */
    public static function reference(Invoice $invoice): string
    {
        return mb_substr('BP'.mb_substr($invoice->public_id, -10).mb_strtoupper(Str::random(8)), 0, 30);
    }
}
