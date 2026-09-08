<?php

declare(strict_types=1);

namespace App\Domain\SaaS\Data;

use App\Domain\SaaS\Enums\SubscriptionPaymentMethod;

/**
 * One payment against a platform invoice. `idempotencyKey` is what makes a replayed gateway callback (or a
 * double-clicked "record payment" button) settle the invoice exactly once — `subscription_payments` has a partial
 * unique index on it, so the database, not the code path, is the guarantee.
 */
final readonly class RecordPaymentData
{
    /** @param  array<string, mixed>  $gatewayPayload */
    public function __construct(
        public int $amountPaisa,
        public SubscriptionPaymentMethod $method,
        public ?string $gatewayTxnId = null,
        public ?string $idempotencyKey = null,
        public array $gatewayPayload = [],
        public ?int $recordedBySuperAdminId = null,
        public ?string $reference = null,
    ) {}
}
