<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Actions\Billing;

use App\Domain\Billing\Data\CheckoutSession;
use App\Domain\Billing\Enums\PaymentGateway;
use App\Domain\SaaS\Data\SubscriptionCheckoutRequest;
use App\Domain\SaaS\Enums\SubscriptionInvoiceStatus;
use App\Domain\SaaS\Enums\SubscriptionPaymentMethod;
use App\Domain\SaaS\Enums\SubscriptionPaymentStatus;
use App\Domain\SaaS\Exceptions\InvoiceNotPayable;
use App\Domain\SaaS\Gateways\SubscriptionGatewayManager;
use App\Models\Central\SubscriptionInvoice;
use App\Models\Central\SubscriptionPayment;

/**
 * Freeze the amount, then hand the browser to the gateway.
 *
 * The pending `subscription_payments` row is created BEFORE the redirect and carries the amount we intend to
 * collect. That row is the merchant reference, so when the callback comes back the amount is something we wrote,
 * not something the callback claimed — which is the entire reason Billing's contract is shaped this way.
 */
final class StartSubscriptionCheckout
{
    public function __construct(private readonly SubscriptionGatewayManager $gateways) {}

    public function handle(SubscriptionInvoice $invoice, PaymentGateway $gateway, string $callbackUrl, string $cancelUrl, ?string $payerMobile = null): CheckoutSession
    {
        if (! in_array($invoice->status, [SubscriptionInvoiceStatus::Issued, SubscriptionInvoiceStatus::Overdue], true)) {
            throw new InvoiceNotPayable($invoice->number, $invoice->status->value);
        }

        $due = $invoice->total_paisa - (int) $invoice->getAttribute('paid_paisa');

        if ($due <= 0) {
            throw new InvoiceNotPayable($invoice->number, 'nothing_due');
        }

        $driver = $this->gateways->configuredDriver($gateway);

        $payment = SubscriptionPayment::query()->create([
            'tenant_id' => $invoice->tenant_id,
            'subscription_invoice_id' => $invoice->id,
            'method' => SubscriptionPaymentMethod::from($gateway->value),
            'status' => SubscriptionPaymentStatus::Pending,
            'amount_paisa' => $due,
            'gateway_payload' => [],
        ]);

        $session = $driver->createCheckout(new SubscriptionCheckoutRequest(
            invoice: $invoice,
            amountPaisa: $due,
            merchantRef: $payment->public_id,
            callbackUrl: $callbackUrl,
            cancelUrl: $cancelUrl,
            payerMobile: $payerMobile,
        ));

        $payment->forceFill([
            'gateway_txn_id' => $session->gatewayTxnId,
            'gateway_payload' => $session->payload,
        ])->save();

        return $session;
    }
}
