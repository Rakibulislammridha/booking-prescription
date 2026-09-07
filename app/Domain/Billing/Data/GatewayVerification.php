<?php

declare(strict_types=1);

namespace App\Domain\Billing\Data;

/** The gateway's own, authoritative answer, obtained server-to-server. This is the only amount we believe. */
final readonly class GatewayVerification
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public bool $succeeded,
        public int $amountPaisa,
        public ?string $gatewayTxnId,
        public string $currency = 'BDT',
        public array $payload = [],
        public ?string $failureReason = null,
    ) {}

    /** @param array<string, mixed> $payload */
    public static function failed(string $reason, array $payload = []): self
    {
        return new self(false, 0, null, 'BDT', $payload, $reason);
    }
}
