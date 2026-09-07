<?php

declare(strict_types=1);

namespace App\Domain\Billing\Data;

use App\Domain\Billing\Services\Paisa;
use App\Models\Tenant\Invoice;

/**
 * What we ask a gateway to collect. `amountPaisa` and `merchantRef` are OURS and are already frozen on a pending
 * `payments` row before this leaves the building — the callback can therefore never dictate an amount.
 */
final readonly class CheckoutRequest
{
    public function __construct(
        public Invoice $invoice,
        public int $amountPaisa,
        public string $merchantRef,
        public string $callbackUrl,
        public string $cancelUrl,
        public ?string $payerMobile = null,
    ) {}

    /** Gateways take BDT with two decimals as a string; never a float. */
    public function amountTaka(): string
    {
        return Paisa::toDecimal($this->amountPaisa);
    }
}
