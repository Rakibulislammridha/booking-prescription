<?php

declare(strict_types=1);

namespace App\Domain\Billing\Data;

/** Where to send the browser, plus whatever handle the gateway minted for the attempt. */
final readonly class CheckoutSession
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public string $redirectUrl,
        public ?string $gatewayTxnId = null,
        public array $payload = [],
    ) {}
}
