<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Data;

use App\Models\Central\SubscriptionInvoice;

/** What the platform asks a gateway to collect. The amount and the reference are ours and already frozen. */
final readonly class SubscriptionCheckoutRequest
{
    public function __construct(
        public SubscriptionInvoice $invoice,
        public int $amountPaisa,
        public string $merchantRef,
        public string $callbackUrl,
        public string $cancelUrl,
        public ?string $payerMobile = null,
    ) {}

    /** Gateways take BDT with two decimals as a string; never a float. */
    public function amountTaka(): string
    {
        return number_format($this->amountPaisa / 100, 2, '.', '');
    }
}
