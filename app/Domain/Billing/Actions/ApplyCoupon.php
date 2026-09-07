<?php

declare(strict_types=1);

namespace App\Domain\Billing\Actions;

use App\Domain\Audit\AuditRecorder;
use App\Domain\Audit\Enums\AuditAction;
use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Exceptions\CouponAlreadyApplied;
use App\Domain\Billing\Exceptions\InvoiceNotEditable;
use App\Domain\Billing\Services\CouponValidator;
use App\Domain\Billing\Services\InvoiceLedger;
use App\Domain\Shared\Actor;
use App\Models\Tenant\Coupon;
use App\Models\Tenant\CouponRedemption;
use App\Models\Tenant\Invoice;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Redeem a coupon on one invoice (BRIEF §5.I). `coupon_redemptions` is UNIQUE per invoice, so a double submit
 * hits the index rather than discounting the bill twice; the cached `uses_count` is bumped inside the same
 * transaction and is never the thing that authorises a redemption.
 */
final class ApplyCoupon
{
    public function __construct(
        private readonly InvoiceLedger $ledger,
        private readonly CouponValidator $validator,
        private readonly AuditRecorder $audit,
    ) {}

    public function handle(Invoice $invoice, Coupon $coupon, Actor $actor): CouponRedemption
    {
        try {
            return DB::transaction(function () use ($invoice, $coupon, $actor): CouponRedemption {
                /** @var Invoice $locked */
                $locked = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();

                if (in_array($locked->status, [InvoiceStatus::Void, InvoiceStatus::Paid, InvoiceStatus::Refunded], true)) {
                    throw new InvoiceNotEditable(['status' => $locked->status->value]);
                }

                $locked->load('items', 'appointment');
                $reducible = max(0, $locked->subtotal_paisa - $locked->discount_paisa);

                $this->validator->assertUsable($coupon, $locked, $reducible);
                $amount = $this->validator->amountFor($coupon, $reducible);

                $redemption = new CouponRedemption;
                $redemption->forceFill([
                    'coupon_id' => $coupon->id,
                    'invoice_id' => $locked->id,
                    'patient_id' => $locked->patient_id,
                    'amount_paisa' => $amount,
                ])->save();

                $coupon->forceFill(['uses_count' => $coupon->uses_count + 1])->save();

                $synced = $this->ledger->sync($locked->refresh());

                $this->audit->record(AuditAction::Update, $synced, null, [
                    'coupon_code' => $coupon->code,
                    'coupon_discount_paisa' => $amount,
                    'total_paisa' => $synced->total_paisa,
                ], ['actor_user_id' => $actor->userId, 'actor_source' => $actor->source, 'event' => 'coupon_applied']);

                return $redemption;
            });
        } catch (QueryException $e) {
            if (CouponRedemption::query()->where('invoice_id', $invoice->id)->exists()) {
                throw new CouponAlreadyApplied;
            }

            throw $e;
        }
    }
}
