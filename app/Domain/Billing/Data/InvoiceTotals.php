<?php

declare(strict_types=1);

namespace App\Domain\Billing\Data;

/** The five derived money columns of an invoice; `due_paisa` is GENERATED in Postgres and absent here. */
final readonly class InvoiceTotals
{
    public function __construct(
        public int $subtotalPaisa,
        public int $discountPaisa,
        public int $couponDiscountPaisa,
        public int $vatPaisa,
        public int $totalPaisa,
    ) {}

    /** @return array<string, int> the invoice columns */
    public function toColumns(): array
    {
        return [
            'subtotal_paisa' => $this->subtotalPaisa,
            'discount_paisa' => $this->discountPaisa,
            'coupon_discount_paisa' => $this->couponDiscountPaisa,
            'vat_paisa' => $this->vatPaisa,
            'total_paisa' => $this->totalPaisa,
        ];
    }
}
