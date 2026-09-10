<?php

declare(strict_types=1);

namespace App\Http\Controllers\Super\Billing;

use App\Domain\Audit\Enums\CentralAuditAction;
use App\Domain\SaaS\Actions\Billing\GenerateSubscriptionInvoice;
use App\Domain\SaaS\Actions\Billing\IssueSubscriptionInvoice;
use App\Domain\SaaS\Actions\Billing\RecordSubscriptionPayment;
use App\Domain\SaaS\Actions\Billing\VoidSubscriptionInvoice;
use App\Domain\SaaS\Enums\SubscriptionInvoiceStatus;
use App\Domain\SaaS\Enums\SubscriptionPaymentMethod;
use App\Domain\SaaS\Queries\BillingInvoices;
use App\Domain\SaaS\Services\CentralAudit;
use App\Domain\SaaS\Services\SubscriptionInvoicePdf;
use App\Domain\SaaS\Services\SubscriptionLifecycle;
use App\Http\Controllers\Controller;
use App\Http\Requests\Super\Billing\RaiseInvoiceRequest;
use App\Http\Requests\Super\Billing\RecordPaymentRequest;
use App\Http\Requests\Super\Billing\VoidInvoiceRequest;
use App\Models\Central\SubscriptionInvoice;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;

/**
 * Platform invoices across every clinic: the ledger, and the four things an operator does to one — issue it,
 * void it, record the money that arrived, print it. Every mutation is audited to `audit_logs_central` (the
 * Actions write the void and the payment; issue and the manual raise are written here, because those Actions
 * are shared with the unattended sweeps, which audit as "system" by not auditing at all).
 */
final class InvoiceController extends Controller
{
    public function index(Request $request, BillingInvoices $invoices): InertiaResponse
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'status' => ['nullable', Rule::in([...SubscriptionInvoiceStatus::values(), 'past_due'])],
            'tenant' => ['nullable', 'string', 'max:40'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $filters = [
            'q' => (string) ($validated['q'] ?? ''),
            'status' => (string) ($validated['status'] ?? ''),
            'tenant' => (string) ($validated['tenant'] ?? ''),
            'from' => (string) ($validated['from'] ?? ''),
            'to' => (string) ($validated['to'] ?? ''),
        ];
        $page = $invoices->list($filters);

        return Inertia::render('Super/Billing/Invoices', [
            'invoices' => $page['data'],
            'meta' => $page['meta'],
            'filters' => $filters,
            'statuses' => SubscriptionInvoiceStatus::values(),
            'status_counts' => $invoices->statusCounts(),
            'methods' => SubscriptionPaymentMethod::values(),
        ]);
    }

    public function store(RaiseInvoiceRequest $request, SubscriptionLifecycle $lifecycle, GenerateSubscriptionInvoice $generate, IssueSubscriptionInvoice $issue, CentralAudit $audit): RedirectResponse
    {
        $tenant = $request->tenant();
        $subscription = $lifecycle->current($tenant);

        if ($subscription === null) {
            throw ValidationException::withMessages(['tenant' => __('saas.invoices.no_subscription')]);
        }

        $invoice = $generate->handle($subscription, $subscription->current_period_start, $subscription->current_period_end, $request->amountPaisa());
        $before = ['status' => $invoice->status->value];

        if ($request->shouldIssue()) {
            $issue->handle($invoice);
        }

        $audit->record(CentralAuditAction::Create, $tenant, $invoice, $before, ['number' => $invoice->number, 'status' => $invoice->status->value, 'total_paisa' => $invoice->total_paisa]);

        return back()->with('flash.success', __('saas.invoices.flash.created', ['number' => $invoice->number]));
    }

    public function issue(SubscriptionInvoice $invoice, IssueSubscriptionInvoice $issue, CentralAudit $audit): RedirectResponse
    {
        $before = ['status' => $invoice->status->value];
        $issue->handle($invoice);
        $audit->record(CentralAuditAction::Update, $invoice->tenant, $invoice, $before, ['status' => $invoice->status->value, 'due_at' => $invoice->getAttribute('due_at')?->toIso8601String()]);

        return back()->with('flash.success', __('saas.invoices.flash.issued', ['number' => $invoice->number]));
    }

    public function void(VoidInvoiceRequest $request, SubscriptionInvoice $invoice, VoidSubscriptionInvoice $void): RedirectResponse
    {
        $void->handle($invoice, $request->reason());

        return back()->with('flash.warning', __('saas.invoices.flash.voided', ['number' => $invoice->number]));
    }

    public function pay(RecordPaymentRequest $request, SubscriptionInvoice $invoice, RecordSubscriptionPayment $record, CentralAudit $audit): RedirectResponse
    {
        $payment = $record->handle($invoice, $request->toData($invoice));

        // The Action is replay-safe and returns the FIRST row for a repeated reference; the audit row says which.
        $audit->record(CentralAuditAction::Update, $invoice->tenant, $payment, null, [
            'invoice' => $invoice->number,
            'amount_paisa' => $payment->amount_paisa,
            'method' => $payment->method->value,
            'reference' => $payment->getAttribute('gateway_txn_id'),
            'replayed' => ! $payment->wasRecentlyCreated,
            'invoice_status' => $invoice->refresh()->status->value,
        ]);

        return back()->with('flash.success', __('saas.invoices.flash.paid', ['number' => $invoice->number]));
    }

    public function print(SubscriptionInvoice $invoice, SubscriptionInvoicePdf $pdf, CentralAudit $audit): Response
    {
        $audit->record(CentralAuditAction::View, $invoice->tenant, $invoice, null, ['document' => 'subscription_invoice', 'format' => 'html']);

        return $this->html($pdf->html($invoice));
    }

    public function pdf(SubscriptionInvoice $invoice, SubscriptionInvoicePdf $pdf, CentralAudit $audit): Response
    {
        $audit->record(CentralAuditAction::View, $invoice->tenant, $invoice, null, ['document' => 'subscription_invoice', 'format' => $pdf->available() ? 'pdf' : 'html']);

        if (! $pdf->available()) {
            return $this->html($pdf->html($invoice));
        }

        return response($pdf->pdf($invoice), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$invoice->number.'.pdf"',
            'Cache-Control' => 'no-store, private',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }

    /** Same headers as every other print path: never cached, never indexed. */
    private function html(string $html): Response
    {
        return response($html, 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
            'Cache-Control' => 'no-store, private',
            'X-Robots-Tag' => 'noindex, nofollow',
        ]);
    }
}
