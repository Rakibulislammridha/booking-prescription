<?php

declare(strict_types=1);

namespace App\Http\Controllers\Super\Tenants;

use App\Domain\SaaS\Actions\Billing\GenerateSubscriptionInvoice;
use App\Domain\SaaS\Actions\Billing\IssueSubscriptionInvoice;
use App\Domain\SaaS\Actions\Billing\RecordSubscriptionPayment;
use App\Domain\SaaS\Actions\Billing\VoidSubscriptionInvoice;
use App\Domain\SaaS\Data\RecordPaymentData;
use App\Domain\SaaS\Enums\SubscriptionPaymentMethod;
use App\Domain\SaaS\Services\SubscriptionLifecycle;
use App\Http\Controllers\Controller;
use App\Models\Central\SubscriptionInvoice;
use App\Models\Central\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The platform's own accounts-receivable desk: raise an invoice out of cycle, issue it, void a mistake, and
 * record the bank transfer or bKash payment that actually arrived.
 *
 * Recording a payment is the common case in this market — collection is usually a bank transfer against an
 * invoice number, not a card on file — so `idempotency_key` is derived from the operator's own reference: pasting
 * the same transfer reference twice settles the invoice once.
 */
final class BillingController extends Controller
{
    public function store(Request $request, Tenant $tenant, SubscriptionLifecycle $lifecycle, GenerateSubscriptionInvoice $generate, IssueSubscriptionInvoice $issue): RedirectResponse
    {
        $subscription = $lifecycle->current($tenant);

        if ($subscription === null) {
            throw ValidationException::withMessages(['plan' => __('saas.invoices.no_subscription')]);
        }

        $validated = $request->validate([
            'amount_paisa' => ['nullable', 'integer', 'min:0'],
            'issue' => ['boolean'],
        ]);

        $invoice = $generate->handle(
            $subscription,
            $subscription->current_period_start,
            $subscription->current_period_end,
            isset($validated['amount_paisa']) ? (int) $validated['amount_paisa'] : null,
        );

        if ($request->boolean('issue', true)) {
            $issue->handle($invoice);
        }

        return back()->with('flash.success', __('saas.invoices.flash.created', ['number' => $invoice->number]));
    }

    public function issue(Tenant $tenant, SubscriptionInvoice $invoice, IssueSubscriptionInvoice $issue): RedirectResponse
    {
        abort_unless($invoice->tenant_id === $tenant->id, 404);
        $issue->handle($invoice);

        return back()->with('flash.success', __('saas.invoices.flash.issued', ['number' => $invoice->number]));
    }

    public function void(Request $request, Tenant $tenant, SubscriptionInvoice $invoice, VoidSubscriptionInvoice $void): RedirectResponse
    {
        abort_unless($invoice->tenant_id === $tenant->id, 404);
        $validated = $request->validate(['reason' => ['required', 'string', 'max:255']]);
        $void->handle($invoice, (string) $validated['reason']);

        return back()->with('flash.warning', __('saas.invoices.flash.voided', ['number' => $invoice->number]));
    }

    public function pay(Request $request, Tenant $tenant, SubscriptionInvoice $invoice, RecordSubscriptionPayment $record): RedirectResponse
    {
        abort_unless($invoice->tenant_id === $tenant->id, 404);

        $validated = $request->validate([
            'amount_paisa' => ['required', 'integer', 'min:1'],
            'method' => ['required', Rule::in(SubscriptionPaymentMethod::values())],
            'reference' => ['nullable', 'string', 'max:64'],
        ]);

        $reference = isset($validated['reference']) ? trim((string) $validated['reference']) : null;

        $record->handle($invoice, new RecordPaymentData(
            amountPaisa: (int) $validated['amount_paisa'],
            method: SubscriptionPaymentMethod::from((string) $validated['method']),
            gatewayTxnId: $reference,
            idempotencyKey: $reference === null || $reference === '' ? null : 'manual:'.$invoice->id.':'.$reference,
            gatewayPayload: ['recorded_at' => CarbonImmutable::now()->toIso8601String()],
            recordedBySuperAdminId: (int) $request->user('super')?->getAuthIdentifier(),
            reference: $reference,
        ));

        return back()->with('flash.success', __('saas.invoices.flash.paid', ['number' => $invoice->number]));
    }
}
