<?php

declare(strict_types=1);

namespace App\Domain\Billing\Data;

/** The gateway's answer to a refund request. */
final readonly class GatewayRefundResult
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public bool $succeeded,
        public ?string $gatewayRefundId = null,
        public array $payload = [],
        public ?string $failureReason = null,
    ) {}
}
