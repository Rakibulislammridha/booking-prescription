<?php

declare(strict_types=1);

namespace App\Domain\Billing\Actions;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Exceptions\InvoiceNotVoidable;
use App\Domain\Shared\Actor;
use App\Models\Tenant\Invoice;
use Illuminate\Support\Facades\DB;

/**
 * Void a bill that should never have existed. Only possible while NO money has settled against it — an invoice
 * that took a payment is refunded, not voided, because voiding it would leave real money unaccounted for.
 * The row survives (append-only books); `invoices_appointment_id_uniq_p` ignores voided rows so the booking can
 * be re-invoiced cleanly.
 */
final class VoidInvoice
{
    public function __construct(private readonly AuditRecorder $audit) {}

    public function handle(Invoice $invoice, string $reason, Actor $actor): Invoice
    {
        return DB::transaction(function () use ($invoice, $reason, $actor): Invoice {
            /** @var Invoice $locked */
            $locked = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === InvoiceStatus::Void) {
                return $locked;
            }

            if ($locked->paid_paisa > 0 || $locked->payments()->settled()->exists()) {
                throw new InvoiceNotVoidable(['number' => $locked->number]);
            }

            $locked->forceFill([
                'status' => InvoiceStatus::Void,
                'voided_at' => now(),
                'void_reason' => mb_substr($reason, 0, 255),
            ])->save();

            $this->audit->record(AuditAction::Void, $locked, null, ['status' => InvoiceStatus::Void->value, 'void_reason' => $locked->void_reason], ['actor_user_id' => $actor->userId, 'actor_source' => $actor->source]);

            return $locked;
        });
    }
}
