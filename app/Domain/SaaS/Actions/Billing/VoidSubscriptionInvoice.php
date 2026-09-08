<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Actions\Billing;

use App\Domain\Audit\Enums\CentralAuditAction;
use App\Domain\SaaS\Enums\SubscriptionInvoiceStatus;
use App\Domain\SaaS\Exceptions\InvoiceNotEditable;
use App\Domain\SaaS\Services\CentralAudit;
use App\Models\Central\SubscriptionInvoice;
use Carbon\CarbonImmutable;

/** A paid invoice is never voided — a correction is a new row. Voiding a partially paid one needs a refund first. */
final class VoidSubscriptionInvoice
{
    public function __construct(private readonly CentralAudit $audit) {}

    public function handle(SubscriptionInvoice $invoice, string $reason): SubscriptionInvoice
    {
        if ($invoice->status === SubscriptionInvoiceStatus::Paid || (int) $invoice->getAttribute('paid_paisa') > 0 || $invoice->status === SubscriptionInvoiceStatus::Void) {
            throw new InvoiceNotEditable($invoice->number);
        }

        $before = ['status' => $invoice->status->value];
        $invoice->forceFill(['status' => SubscriptionInvoiceStatus::Void, 'voided_at' => CarbonImmutable::now()])->save();

        $this->audit->record(CentralAuditAction::Update, $invoice->tenant, $invoice, $before, ['status' => 'void', 'reason' => $reason]);

        return $invoice;
    }
}
