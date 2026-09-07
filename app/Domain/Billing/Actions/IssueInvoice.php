<?php

declare(strict_types=1);

namespace App\Domain\Billing\Actions;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Events\InvoiceIssued;
use App\Domain\Billing\Services\InvoiceLedger;
use App\Domain\Billing\Services\RevenueShareResolver;
use App\Domain\Shared\Actor;
use App\Models\Tenant\Invoice;
use App\Models\Tenant\InvoiceItem;
use App\Support\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Draft → issued: the moment the bill becomes real. Two things are FROZEN here and never move again:
 *
 *   1. the line items (quantity, unit price, line total) and the invoice's subtotal and VAT;
 *   2. the doctor's commission — the rule in force on the issue date is resolved per line and written into
 *      `doctor_revenue_share_id` / `doctor_share_paisa` / `clinic_share_paisa`, so changing the rule tomorrow
 *      cannot rewrite yesterday's commission report (BRIEF §5.I, SCHEMA §3.5).
 *
 * Idempotent: issuing an already-issued invoice returns it untouched.
 */
final class IssueInvoice
{
    public function __construct(
        private readonly InvoiceLedger $ledger,
        private readonly RevenueShareResolver $shares,
        private readonly AuditRecorder $audit,
    ) {}

    public function handle(Invoice $invoice, Actor $actor): Invoice
    {
        $issued = DB::transaction(function () use ($invoice, $actor): Invoice {
            /** @var Invoice $locked */
            $locked = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== InvoiceStatus::Draft) {
                return $locked;
            }

            $issuedAt = now();
            $this->freezeRevenueShares($locked, $issuedAt->toImmutable()->setTimezone(Clock::timezone())->startOfDay());

            $locked->forceFill(['status' => InvoiceStatus::Issued, 'issued_at' => $issuedAt])->save();
            $synced = $this->ledger->sync($locked->refresh());

            $this->audit->record(AuditAction::Issue, $synced, null, [
                'number' => $synced->number,
                'total_paisa' => $synced->total_paisa,
                'subtotal_paisa' => $synced->subtotal_paisa,
                'discount_paisa' => $synced->discount_paisa,
                'coupon_discount_paisa' => $synced->coupon_discount_paisa,
                'vat_paisa' => $synced->vat_paisa,
            ], ['actor_user_id' => $actor->userId, 'actor_source' => $actor->source]);

            return $synced;
        });

        DB::afterCommit(fn () => InvoiceIssued::dispatch($issued));

        return $issued;
    }

    /** One rule lookup per line, snapshotted; the residue of a percentage always goes to the clinic. */
    private function freezeRevenueShares(Invoice $invoice, CarbonImmutable $on): void
    {
        $this->shares->forget();

        foreach ($invoice->items()->get() as $item) {
            /** @var InvoiceItem $item */
            $doctorId = $item->doctor_id ?? $invoice->doctor_id;

            $rule = $doctorId === null
                ? null
                : $this->shares->resolve($doctorId, $invoice->branch_id, $item->type, $on);

            $item->forceFill($this->shares->split($item->line_total_paisa, $rule)->toColumns())->save();
        }
    }
}
