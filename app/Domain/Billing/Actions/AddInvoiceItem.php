<?php

declare(strict_types=1);

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\Data\InvoiceLineData;
use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Exceptions\InvoiceNotEditable;
use App\Domain\Billing\Services\InvoiceLedger;
use App\Models\Tenant\Invoice;
use App\Models\Tenant\InvoiceItem;
use Illuminate\Support\Facades\DB;

/** Add a charge to a DRAFT invoice. Issued invoices are frozen — adjust them through discounts or refunds. */
final class AddInvoiceItem
{
    public function __construct(private readonly InvoiceLedger $ledger) {}

    public function handle(Invoice $invoice, InvoiceLineData $line): InvoiceItem
    {
        return DB::transaction(function () use ($invoice, $line): InvoiceItem {
            /** @var Invoice $locked */
            $locked = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== InvoiceStatus::Draft) {
                throw new InvoiceNotEditable(['status' => $locked->status->value]);
            }

            $item = new InvoiceItem;
            $item->forceFill([
                'invoice_id' => $locked->id,
                'sort_order' => ((int) $locked->items()->max('sort_order')) + 1,
                'type' => $line->type,
                'description' => $line->description,
                'reference_type' => $line->referenceType,
                'reference_id' => $line->referenceId,
                'doctor_id' => $line->doctorId,
                'quantity' => max(1, $line->quantity),
                'unit_price_paisa' => max(0, $line->unitPricePaisa),
                'line_total_paisa' => max(1, $line->quantity) * max(0, $line->unitPricePaisa),
            ])->save();

            $this->ledger->sync($locked->refresh());

            return $item;
        });
    }

    /** The draft consultation line is REPLACED when Booking writes a new fee snapshot (SCHEMA §5.10). */
    public function replaceConsultationLine(Invoice $invoice, InvoiceLineData $line): void
    {
        DB::transaction(function () use ($invoice, $line): void {
            /** @var Invoice $locked */
            $locked = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== InvoiceStatus::Draft) {
                throw new InvoiceNotEditable(['status' => $locked->status->value]);
            }

            $existing = $locked->items()->where('reference_type', $line->referenceType)->where('reference_id', $line->referenceId)->first();

            if ($existing === null) {
                $this->handle($locked, $line);

                return;
            }

            $existing->forceFill([
                'type' => $line->type,
                'description' => $line->description,
                'unit_price_paisa' => max(0, $line->unitPricePaisa),
                'line_total_paisa' => max(1, $line->quantity) * max(0, $line->unitPricePaisa),
                'quantity' => max(1, $line->quantity),
            ])->save();

            $this->ledger->sync($locked->refresh());
        });
    }
}
