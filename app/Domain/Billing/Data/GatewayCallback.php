<?php

declare(strict_types=1);

namespace App\Domain\Billing\Data;

use App\Domain\Billing\Enums\PaymentGateway;

/**
 * A signature-verified inbound callback. It carries IDENTIFIERS ONLY — no amount, deliberately: the amount is
 * always fetched server-to-server in `verifyTransaction()` and compared with the amount we froze at checkout.
 */
final readonly class GatewayCallback
{
    /** @param array<string, mixed> $payload */
    public function __construct(
        public PaymentGateway $gateway,
        public ?string $merchantRef,
        public ?string $gatewayTxnId,
        public string $rawStatus,
        public array $payload = [],
    ) {}

    /** The patient (or the gateway) told us the attempt was abandoned. */
    public function isCancelled(): bool
    {
        return in_array(mb_strtolower($this->rawStatus), ['cancel', 'cancelled', 'canceled', 'aborted', 'user_cancel'], true);
    }
}
