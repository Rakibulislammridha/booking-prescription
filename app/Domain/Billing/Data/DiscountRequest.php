<?php

declare(strict_types=1);

namespace App\Domain\Billing\Data;

use App\Domain\Billing\Enums\DiscountReason;
use App\Domain\Billing\Enums\DiscountType;

/**
 * What the desk asked for. `value` is AS ENTERED — a percentage 0-100 for `percentage`, BDT taka for `fixed`
 * (the same convention as `coupons.value` and `doctor_revenue_shares.share_value`). The resolved integer paisa
 * that actually moves money is computed by InvoiceCalculator and stored in `discounts.amount_paisa`.
 */
final readonly class DiscountRequest
{
    public function __construct(
        public DiscountType $type,
        public string $value,
        public DiscountReason $reasonCode,
        public ?string $note = null,
        public ?int $approvedByUserId = null,
    ) {}
}
