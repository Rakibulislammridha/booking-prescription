<?php

declare(strict_types=1);

namespace App\Http\Controllers\Super\Tenants;

use App\Domain\Audit\Enums\CentralAuditAction;
use App\Domain\SaaS\Actions\Billing\GenerateSubscriptionInvoice;
use App\Domain\SaaS\Actions\Billing\IssueSubscriptionInvoice;
use App\Domain\SaaS\Actions\Billing\RecordSubscriptionPayment;
use App\Domain\SaaS\Actions\Billing\VoidSubscriptionInvoice;
use App\Domain\SaaS\Services\CentralAudit;
use App\Domain\SaaS\Services\SubscriptionLifecycle;
use App\Http\Controllers\Controller;
use App\Http\Requests\Super\Billing\RecordPaymentRequest;
use App\Http\Requests\Super\Billing\VoidInvoiceRequest;
use App\Models\Central\SubscriptionInvoice;
use App\Models\Central\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The tenant-scoped accounts-receivable desk (`super.tenants.invoices.*`): raise an invoice out of cycle, issue
 * it, void a mistake, and record the bank transfer or bKash payment that actually arrived. The platform-wide
 * desk (`Super\Billing\InvoiceController`) does the same four things by invoice alone; both share the same
 * FormRequests, so the rules — integer paisa, a required reference that becomes the idempotency key — are one.
 *
 * Every invoice here is checked against the tenant in the URL: a public_id from another clinic is a 404, not a
 * payment recorded against the wrong customer.
 */
final class BillingController extends Controller
{
    public function store(Request $request, Tenant $tenant, SubscriptionLifecycle $lifecycle, GenerateSubscriptionInvoice $generate, IssueSubscriptionInvoice $issue, CentralAudit $audit): RedirectResponse
    {
        $subscription = $lifecycle->current($tenant);

        if ($subscription === null) {
            throw ValidationException::withMessages(['plan' => __('saas.invoices.no_subscription')]);
        }

        $validated = $request->validate([
            'amount_paisa' => ['nullable', 'integer', 'min:0', 'max:100000000000'],
            'issue' => ['boolean'],
        ]);

        $invoice = $generate->handle(
            $subscription,
            $subscription->current_period_start,
            $subscription->current_period_end,
            isset($validated['amount_paisa']) ? (int) $validated['amount_paisa'] : null,
        );
        $before = ['status' => $invoice->status->value];

        if ($request->boolean('issue', true)) {
            $issue->handle($invoice);
        }

        $audit->record(CentralAuditAction::Create, $tenant, $invoice, $before, ['number' => $invoice->number, 'status' => $invoice->status->value, 'total_paisa' => $invoice->total_paisa]);

        return back()->with('flash.success', __('saas.invoices.flash.created', ['number' => $invoice->number]));
    }

    public function issue(Tenant $tenant, SubscriptionInvoice $invoice, IssueSubscriptionInvoice $issue, CentralAudit $audit): RedirectResponse
    {
        abort_unless($invoice->tenant_id === $tenant->id, 404);
        $before = ['status' => $invoice->status->value];
        $issue->handle($invoice);
        $audit->record(CentralAuditAction::Update, $tenant, $invoice, $before, ['status' => $invoice->status->value, 'due_at' => $invoice->getAttribute('due_at')?->toIso8601String()]);

        return back()->with('flash.success', __('saas.invoices.flash.issued', ['number' => $invoice->number]));
    }

    public function void(VoidInvoiceRequest $request, Tenant $tenant, SubscriptionInvoice $invoice, VoidSubscriptionInvoice $void): RedirectResponse
    {
        abort_unless($invoice->tenant_id === $tenant->id, 404);
        $void->handle($invoice, $request->reason());

        return back()->with('flash.warning', __('saas.invoices.flash.voided', ['number' => $invoice->number]));
    }

    public function pay(RecordPaymentRequest $request, Tenant $tenant, SubscriptionInvoice $invoice, RecordSubscriptionPayment $record, CentralAudit $audit): RedirectResponse
    {
        abort_unless($invoice->tenant_id === $tenant->id, 404);

        $payment = $record->handle($invoice, $request->toData($invoice));

        $audit->record(CentralAuditAction::Update, $tenant, $payment, null, [
            'invoice' => $invoice->number,
            'amount_paisa' => $payment->amount_paisa,
            'method' => $payment->method->value,
            'reference' => $payment->getAttribute('gateway_txn_id'),
            'replayed' => ! $payment->wasRecentlyCreated,
            'invoice_status' => $invoice->refresh()->status->value,
        ]);

        return back()->with('flash.success', __('saas.invoices.flash.paid', ['number' => $invoice->number]));
    }
}
