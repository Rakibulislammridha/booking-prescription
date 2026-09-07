<?php

declare(strict_types=1);

namespace App\Domain\Billing\Services;

use App\Domain\Billing\Enums\DiscountType;
use App\Domain\Billing\Exceptions\CouponAlreadyApplied;
use App\Domain\Billing\Exceptions\CouponInvalid;
use App\Models\Tenant\Coupon;
use App\Models\Tenant\CouponRedemption;
use App\Models\Tenant\Invoice;

/**
 * Coupon validity and the paisa it is worth on one invoice (BRIEF §5.I). Every check throws `CouponInvalid` with a
 * translated reason; the caller shows it verbatim. The redemption LIMIT is checked here but enforced by the
 * database (`coupon_redemptions` UNIQUE (invoice_id)) — the check is the message, the index is the guarantee.
 */
final class CouponValidator
{
    public function __construct(private readonly InvoiceCalculator $calculator) {}

    /** @throws CouponInvalid|CouponAlreadyApplied */
    public function assertUsable(Coupon $coupon, Invoice $invoice, int $reduciblePaisa): void
    {
        if (! $coupon->is_active) {
            throw new CouponInvalid(['reason' => __('billing.coupon.reason.inactive')]);
        }

        $now = now();

        if ($coupon->valid_from !== null && $now->lessThan($coupon->valid_from)) {
            throw new CouponInvalid(['reason' => __('billing.coupon.reason.not_started')]);
        }

        if ($coupon->valid_until !== null && $now->greaterThan($coupon->valid_until)) {
            throw new CouponInvalid(['reason' => __('billing.coupon.reason.expired')]);
        }

        if ($invoice->subtotal_paisa < $coupon->min_invoice_paisa) {
            throw new CouponInvalid(['reason' => __('billing.coupon.reason.min_invoice')]);
        }

        if ($reduciblePaisa <= 0) {
            throw new CouponInvalid(['reason' => __('billing.coupon.reason.nothing_to_reduce')]);
        }

        if (CouponRedemption::query()->where('invoice_id', $invoice->id)->exists()) {
            throw new CouponAlreadyApplied;
        }

        // The authoritative usage count is the redemption rows, not the cached uses_count column.
        $used = CouponRedemption::query()->where('coupon_id', $coupon->id)->count();

        if ($coupon->max_uses !== null && $used >= $coupon->max_uses) {
            throw new CouponInvalid(['reason' => __('billing.coupon.reason.exhausted')]);
        }

        $usedByPatient = CouponRedemption::query()->where('coupon_id', $coupon->id)->where('patient_id', $invoice->patient_id)->count();

        if ($usedByPatient >= $coupon->max_uses_per_patient) {
            throw new CouponInvalid(['reason' => __('billing.coupon.reason.per_patient')]);
        }

        $this->assertInScope($coupon, $invoice);
    }

    /** Percentage coupons are capped by `max_discount_paisa` and then by what is left to reduce. */
    public function amountFor(Coupon $coupon, int $reduciblePaisa): int
    {
        $amount = $coupon->type === DiscountType::Percentage
            ? Paisa::applyBasisPoints($reduciblePaisa, Paisa::percentToBasisPoints($coupon->value))
            : Paisa::fromDecimal($coupon->value);

        if ($coupon->max_discount_paisa !== null) {
            $amount = min($amount, $coupon->max_discount_paisa);
        }

        return Paisa::clamp($amount, $reduciblePaisa);
    }

    /** `applies_to` allow-lists: an empty or absent list means "any" (SCHEMA §3.5). */
    private function assertInScope(Coupon $coupon, Invoice $invoice): void
    {
        $doctors = $coupon->allowList('doctor_ids');

        if ($doctors !== [] && ! in_array($invoice->doctor_id, array_map('intval', $doctors), true)) {
            throw new CouponInvalid(['reason' => __('billing.coupon.reason.doctor')]);
        }

        $branches = $coupon->allowList('branch_ids');

        if ($branches !== [] && ! in_array($invoice->branch_id, array_map('intval', $branches), true)) {
            throw new CouponInvalid(['reason' => __('billing.coupon.reason.branch')]);
        }

        $itemTypes = $coupon->allowList('item_types');

        if ($itemTypes !== []) {
            $present = $invoice->items->map(fn ($item) => $item->type->value)->all();

            if (array_intersect(array_map('strval', $itemTypes), $present) === []) {
                throw new CouponInvalid(['reason' => __('billing.coupon.reason.item_type')]);
            }
        }

        $channels = $coupon->allowList('channels');

        if ($channels !== [] && $invoice->appointment !== null && ! in_array($invoice->appointment->channel->value, array_map('strval', $channels), true)) {
            throw new CouponInvalid(['reason' => __('billing.coupon.reason.channel')]);
        }
    }

    public function calculator(): InvoiceCalculator
    {
        return $this->calculator;
    }
}
