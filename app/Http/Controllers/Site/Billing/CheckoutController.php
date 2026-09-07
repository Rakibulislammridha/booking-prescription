<?php

declare(strict_types=1);

namespace App\Http\Controllers\Site\Billing;

use App\Domain\Billing\Actions\CreateInvoiceForAppointment;
use App\Domain\Billing\Actions\IssueInvoice;
use App\Domain\Billing\Actions\StartOnlinePayment;
use App\Domain\Billing\Enums\PaymentGateway;
use App\Domain\Billing\Gateways\GatewayManager;
use App\Domain\Shared\Actor;
use App\Http\Controllers\Controller;
use App\Models\Tenant\Appointment;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The patient-facing online payment step (BRIEF §5.I). Public, because a patient who just booked online is not
 * logged in — which is exactly why nothing here trusts the request: the amount comes from the appointment's
 * frozen fee snapshot via the invoice, and the checkout is rate limited.
 */
final class CheckoutController extends Controller
{
    public function show(Request $request, Appointment $appointment, GatewayManager $gateways, CreateInvoiceForAppointment $invoices, IssueInvoice $issue): Response
    {
        $invoice = $invoices->handle($appointment, Actor::system());

        if ($invoice->isDraft()) {
            $invoice = $issue->handle($invoice, Actor::system());
        }

        return Inertia::render('Billing/Pay', [
            'appointment' => ['public_id' => $appointment->public_id, 'scheduled_date' => $appointment->scheduled_date?->toDateString()],
            'invoice' => [
                'number' => $invoice->number,
                'total_paisa' => $invoice->total_paisa,
                'due_paisa' => $invoice->due_paisa,
                'status' => $invoice->status->value,
            ],
            'gateways' => array_map(fn (PaymentGateway $g) => $g->value, $gateways->available()),
            'labels' => self::labels(),
        ]);
    }

    /**
     * The site bundle only carries the key prefixes of `shared/lang/surfaces.ts`, so `billing.*` captions are
     * translated HERE and passed as data — no foundation change to the allowlist, and no raw keys in production.
     *
     * @return array<string, string>
     */
    private static function labels(): array
    {
        return [
            'invoice_no' => __('billing.index.number'),
            'due' => __('billing.index.due'),
            'already_paid' => __('billing.status.paid'),
            'pay_at_counter' => __('billing.pay.at_counter'),
            'choose_method' => __('billing.pay.choose_method'),
            'secure_notice' => __('billing.pay.secure_notice'),
            'method_bkash' => __('billing.method.bkash'),
            'method_nagad' => __('billing.method.nagad'),
            'method_sslcommerz' => __('billing.method.sslcommerz'),
        ];
    }

    /** Start the gateway checkout and hand the browser over. */
    public function start(Request $request, Appointment $appointment, StartOnlinePayment $start, CreateInvoiceForAppointment $invoices, IssueInvoice $issue): RedirectResponse
    {
        $gateway = PaymentGateway::tryFrom((string) $request->input('gateway', ''));
        abort_if($gateway === null, 404);

        $invoice = $invoices->handle($appointment, Actor::system());

        if ($invoice->isDraft()) {
            $invoice = $issue->handle($invoice, Actor::system());
        }

        $result = $start->handle(
            invoice: $invoice,
            gateway: $gateway,
            actor: Actor::fromRequest($request),
            callbackUrl: route('site.billing.callback', ['gateway' => $gateway->value]),
            cancelUrl: route('site.billing.checkout', ['appointment' => $appointment->public_id]),
            payerMobile: $invoice->patient->mobile ?? null,
        );

        return redirect()->away($result['session']->redirectUrl);
    }
}
