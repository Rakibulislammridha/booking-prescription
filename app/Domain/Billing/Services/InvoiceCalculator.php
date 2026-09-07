<?php

declare(strict_types=1);

namespace App\Domain\Billing\Services;

use App\Domain\Billing\Data\InvoiceTotals;
use App\Domain\Billing\Enums\DiscountType;

/**
 * The one place invoice money is added up (BRIEF §5.I). Pure: integers in, integers out, no database, no floats.
 *
 *   subtotal = Σ line_total (each = quantity × unit_price, a database CHECK)
 *   base     = subtotal − discounts − coupon           (clamped at 0; discounts can never make a bill negative)
 *   vat      = base × billing.vat_percent              (basis points, half-up)
 *   total    = base + vat
 *   due      = total − paid                            (a GENERATED column; never written by code)
 */
final class InvoiceCalculator
{
    /**
     * @param  array<int, int>  $lineTotalsPaisa
     */
    public function totals(array $lineTotalsPaisa, int $discountPaisa, int $couponDiscountPaisa, int $vatBasisPoints): InvoiceTotals
    {
        $subtotal = 0;

        foreach ($lineTotalsPaisa as $line) {
            $subtotal += max(0, $line);
        }

        // Discounts are applied in order and each is capped by what is left, so their sum can never exceed the
        // subtotal — a bill is never negative and a refundable credit is never created by accident.
        $discount = Paisa::clamp($discountPaisa, $subtotal);
        $coupon = Paisa::clamp($couponDiscountPaisa, $subtotal - $discount);
        $base = $subtotal - $discount - $coupon;
        $vat = Paisa::applyBasisPoints($base, $vatBasisPoints);

        return new InvoiceTotals($subtotal, $discount, $coupon, $vat, $base + $vat);
    }

    /** The resolved paisa amount of one discount line against the amount it may still reduce. */
    public function discountAmount(DiscountType $type, string|int|float $value, int $reduciblePaisa): int
    {
        $amount = $type === DiscountType::Percentage
            ? Paisa::applyBasisPoints($reduciblePaisa, Paisa::percentToBasisPoints($value))
            : Paisa::fromDecimal($value);

        return Paisa::clamp($amount, $reduciblePaisa);
    }

    /** quantity × unit price, the value the `invoice_items_line_total_paisa_check` constraint demands. */
    public function lineTotal(int $quantity, int $unitPricePaisa): int
    {
        return max(1, $quantity) * max(0, $unitPricePaisa);
    }
}
