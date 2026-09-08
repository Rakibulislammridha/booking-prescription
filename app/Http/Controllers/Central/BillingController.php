<?php

declare(strict_types=1);

namespace App\Http\Controllers\Central;

use App\Domain\Billing\Enums\PaymentGateway;
use App\Domain\Billing\Exceptions\GatewaySignatureInvalid;
use App\Domain\SaaS\Actions\Billing\SettleSubscriptionGatewayPayment;
use App\Domain\SaaS\Actions\Billing\StartSubscriptionCheckout;
use App\Domain\SaaS\Enums\SubscriptionInvoiceStatus;
use App\Domain\SaaS\Enums\TenantStatus;
use App\Domain\SaaS\Gateways\SubscriptionGatewayManager;
use App\Domain\SaaS\Support\CentralCopy;
use App\Http\Controllers\Central\Concerns\BuildsCentralLinks;
use App\Http\Controllers\Controller;
use App\Models\Central\SubscriptionInvoice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * Paying a PLATFORM invoice, on the CENTRAL host — and that host is the whole point.
 *
 * A suspended tenant's own host answers 402 on every route (`EnsureTenantIsActive`), so a pay page living in the
 * panel would be unreachable at exactly the moment the customer wants to use it. These routes are on the bare
 * platform domain, behind a SIGNED URL (the page names a clinic and an amount, so it must not be guessable from
 * an invoice id) that the dunning email and the panel both mint.
 *
 * The gateway return is a GET, which needs no CSRF exemption and is what bKash/SSLCommerz actually redirect to;
 * the signature is verified by the driver and the amount is re-fetched server-to-server before anything is
 * credited (`SettleSubscriptionGatewayPayment`).
 */
final class BillingController extends Controller
{
    use BuildsCentralLinks;

    public function show(SubscriptionInvoice $invoice, SubscriptionGatewayManager $gateways): Response
    {
        $tenant = $invoice->tenant;
        $paid = (int) $invoice->getAttribute('paid_paisa');
        $due = max(0, $invoice->total_paisa - $paid);
        $payable = $due > 0 && in_array($invoice->status, [SubscriptionInvoiceStatus::Issued, SubscriptionInvoiceStatus::Overdue], true);

        return Inertia::render('Central/Billing/Invoice', [
            'invoice' => [
                'public_id' => $invoice->public_id,
                'number' => $invoice->number,
                'status' => $invoice->status->value,
                'total_paisa' => $invoice->total_paisa,
                'paid_paisa' => $paid,
                'due_paisa' => $due,
                'issued_at' => $invoice->getAttribute('issued_at')?->toIso8601String(),
                'due_at' => $invoice->getAttribute('due_at')?->toIso8601String(),
                'period_start' => $invoice->getAttribute('period_start')?->toDateString(),
                'period_end' => $invoice->getAttribute('period_end')?->toDateString(),
                'line_items' => $invoice->line_items,
            ],
            'tenant' => ['name' => $tenant->name, 'slug' => $tenant->slug],
            'gateways' => array_map(fn (PaymentGateway $g) => ['value' => $g->value, 'label' => __('saas.gateway.'.$g->value)], $gateways->available()),
            'pay_url' => $payable ? $this->signedInvoiceUrl($invoice, 'central.billing.pay') : null,
            'suspended' => $tenant->status === TenantStatus::Suspended,
            'links' => $this->centralLinks(),
            'copy' => CentralCopy::for('invoice'),
        ]);
    }

    public function pay(Request $request, SubscriptionInvoice $invoice, StartSubscriptionCheckout $checkout): RedirectResponse
    {
        $validated = $request->validate(['gateway' => ['required', Rule::in(PaymentGateway::values())]]);
        $gateway = PaymentGateway::from((string) $validated['gateway']);

        $session = $checkout->handle(
            $invoice,
            $gateway,
            callbackUrl: route('central.billing.callback', ['gateway' => $gateway->value]),
            cancelUrl: $this->signedInvoiceUrl($invoice),
        );

        return redirect()->away($session->redirectUrl);
    }

    public function callback(Request $request, string $gateway, SubscriptionGatewayManager $gateways, SettleSubscriptionGatewayPayment $settle): HttpResponse
    {
        $enum = PaymentGateway::tryFrom($gateway);

        if ($enum === null) {
            abort(404);
        }

        try {
            $callback = $gateways->driver($enum)->verifyCallback($request);
        } catch (GatewaySignatureInvalid) {
            abort(400, __('saas.payments.callback_invalid'));
        }

        $payment = $settle->handle($callback);
        $invoice = $payment?->invoice;

        if ($invoice === null) {
            return redirect()->route('central.home')->with('flash.error', __('saas.payments.callback_invalid'));
        }

        return redirect()->to($this->signedInvoiceUrl($invoice))->with('flash.success', __('saas.payments.thanks'));
    }
}
