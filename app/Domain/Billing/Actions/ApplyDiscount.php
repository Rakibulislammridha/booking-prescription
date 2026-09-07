<?php

declare(strict_types=1);

namespace App\Domain\Billing\Actions;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Billing\Data\DiscountRequest;
use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Exceptions\DiscountApprovalRequired;
use App\Domain\Billing\Exceptions\InvoiceNotEditable;
use App\Domain\Billing\Services\InvoiceLedger;
use App\Domain\Billing\Services\Paisa;
use App\Domain\Clinic\Enums\Permission;
use App\Domain\Clinic\Services\Settings;
use App\Domain\Shared\Actor;
use App\Models\Tenant\Discount;
use App\Models\Tenant\Invoice;
use App\Models\Tenant\User;
use Illuminate\Support\Facades\DB;

/**
 * A waiver with a reason (BRIEF §5.I). Above `settings.billing.discount_approval_threshold_paisa` the row must
 * carry an approver holding `billing.discounts.approve` — the threshold is checked on the RESOLVED paisa, not on
 * the percentage the user typed.
 *
 * A discount is a new row, never an edit of the bill: the invoice totals are then recomputed from all rows. It is
 * refused when it would drop the total below what has already been collected (`DiscountBelowPaid`) — that money
 * has to go back through a refund, which leaves a trail.
 */
final class ApplyDiscount
{
    public const APPROVAL_THRESHOLD = 'billing.discount_approval_threshold_paisa';

    public function __construct(
        private readonly InvoiceLedger $ledger,
        private readonly Settings $settings,
        private readonly AuditRecorder $audit,
    ) {}

    public function handle(Invoice $invoice, DiscountRequest $request, Actor $actor): Discount
    {
        return DB::transaction(function () use ($invoice, $request, $actor): Discount {
            /** @var Invoice $locked */
            $locked = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

            if (in_array($locked->status, [InvoiceStatus::Void, InvoiceStatus::Paid, InvoiceStatus::Refunded], true)) {
                throw new InvoiceNotEditable(['status' => $locked->status->value]);
            }

            $reducible = max(0, $locked->subtotal_paisa - $locked->discount_paisa - $locked->coupon_discount_paisa);
            $amount = $this->ledger->calculator()->discountAmount($request->type, $request->value, $reducible);

            $approver = $this->resolveApprover($amount, $request->approvedByUserId);

            $discount = new Discount;
            $discount->forceFill([
                'invoice_id' => $locked->id,
                'type' => $request->type,
                'value' => Paisa::toDecimal(Paisa::fromDecimal($request->value)),
                'amount_paisa' => $amount,
                'reason_code' => $request->reasonCode,
                'note' => $request->note,
                'applied_by_user_id' => $actor->userId,
                'approved_by_user_id' => $approver,
            ])->save();

            $synced = $this->ledger->sync($locked->refresh());

            $this->audit->record(AuditAction::Update, $synced, null, [
                'discount_applied_paisa' => $amount,
                'reason_code' => $request->reasonCode->value,
                'approved_by_user_id' => $approver,
                'total_paisa' => $synced->total_paisa,
            ], ['actor_user_id' => $actor->userId, 'actor_source' => $actor->source, 'event' => 'discount_applied']);

            return $discount;
        });
    }

    /** Below the threshold no approval is needed; above it an approver with the permission is mandatory. */
    private function resolveApprover(int $amountPaisa, ?int $approverId): ?int
    {
        $threshold = (int) $this->settings->get(self::APPROVAL_THRESHOLD);

        if ($amountPaisa <= $threshold) {
            return $approverId;
        }

        $approver = $approverId === null ? null : User::query()->find($approverId);

        if ($approver === null || ! $approver->can(Permission::BillingDiscountsApprove->value)) {
            throw new DiscountApprovalRequired([
                'threshold' => Paisa::toDecimal($threshold),
                'amount' => Paisa::toDecimal($amountPaisa),
            ]);
        }

        return $approver->id;
    }
}
