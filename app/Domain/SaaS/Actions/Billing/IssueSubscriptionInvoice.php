<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Actions\Billing;

use App\Domain\SaaS\Enums\SubscriptionInvoiceStatus;
use App\Domain\SaaS\Events\SubscriptionInvoiceIssued;
use App\Domain\SaaS\Exceptions\InvoiceNotEditable;
use App\Domain\SaaS\Support\DunningSchedule;
use App\Models\Central\SubscriptionInvoice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Draft → issued. This is the moment the clinic owes money and the dunning clock starts, so it is also the moment
 * the invoice becomes immutable: everything after this is a payment, a dunning step or a void, never an edit.
 *
 * A zero-total invoice (a free plan, a fully discounted period) is issued and settled in the same breath — there
 * is nothing to collect and leaving it "issued" would eventually suspend a clinic that owes nothing.
 */
final class IssueSubscriptionInvoice
{
    public function handle(SubscriptionInvoice $invoice, ?CarbonImmutable $dueAt = null): SubscriptionInvoice
    {
        if ($invoice->status !== SubscriptionInvoiceStatus::Draft) {
            throw new InvoiceNotEditable($invoice->number);
        }

        $now = CarbonImmutable::now();
        $due = $dueAt ?? $now->addDays(DunningSchedule::NET_DAYS);
        $settled = $invoice->total_paisa === 0;

        DB::connection('pgsql')->transaction(function () use ($invoice, $now, $due, $settled): void {
            $invoice->forceFill([
                'status' => $settled ? SubscriptionInvoiceStatus::Paid : SubscriptionInvoiceStatus::Issued,
                'issued_at' => $now,
                'due_at' => $due,
                'paid_at' => $settled ? $now : null,
            ])->save();
        });

        if (! $settled) {
            SubscriptionInvoiceIssued::dispatch($invoice->tenant_id, $invoice->id, $invoice->number, $invoice->total_paisa);
        }

        return $invoice;
    }
}
