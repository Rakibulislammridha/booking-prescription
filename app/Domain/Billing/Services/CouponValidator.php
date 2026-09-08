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
 * translated reason; the caller shows it verbatim.
 *
 * The usage CAPS (`max_uses`, `max_uses_per_patient`) are decided here by counting `coupon_redemptions` rows, and
 * that count is only meaningful while every competing redemption of the same coupon is serialised: the caller
 * MUST already hold `FOR UPDATE` on the `coupons` row (`ApplyCoupon::lockCoupon()`) — the same invariant the
 * serial engine calls I-OWNER (SERIAL_ENGINE §4). `coupon_redemptions_invoice_id_uniq` guarantees only that one
 * invoice carries one coupon; it says nothing about the caps. The caps are backstopped by the unique ordinals
 * this method returns (`coupon_redemptions_coupon_use_seq_uniq`, `coupon_redemptions_patient_use_seq_uniq`).
 */
final class CouponValidator
{
    public function __construct(private readonly InvoiceCalculator $calculator) {}

    /**
     * @return array{coupon_use_seq: int, patient_use_seq: int} the 1-based ordinals this redemption would take,
     *                                                          to be written on the `coupon_redemptions` row
     *
     * @throws CouponInvalid|CouponAlreadyApplied
     */
    public function assertUsable(Coupon $coupon, Invoice $invoice, int $reduciblePaisa): array
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

        // The authoritative usage count is the redemption rows, not the cached uses_count column. Read under the
        // caller's FOR UPDATE on the coupon, so no other transaction can be between its count and its insert.
        $used = CouponRedemption::query()->where('coupon_id', $coupon->id)->count();

        if ($coupon->max_uses !== null && $used >= $coupon->max_uses) {
            throw new CouponInvalid(['reason' => __('billing.coupon.reason.exhausted')]);
        }

        $usedByPatient = CouponRedemption::query()->where('coupon_id', $coupon->id)->where('patient_id', $invoice->patient_id)->count();

        if ($usedByPatient >= $coupon->max_uses_per_patient) {
            throw new CouponInvalid(['reason' => __('billing.coupon.reason.per_patient')]);
        }

        $this->assertInScope($coupon, $invoice);

        return ['coupon_use_seq' => $used + 1, 'patient_use_seq' => $usedByPatient + 1];
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
