<?php

declare(strict_types=1);

namespace App\Domain\Billing\Data;

use App\Domain\Billing\Enums\PaymentMethod;
use App\Domain\Billing\Enums\RefundReason;

/** A refund as asked for. `amountPaisa` null means "everything still refundable on this payment". */
final readonly class RefundRequest
{
    public function __construct(
        public RefundReason $reasonCode,
        public ?int $amountPaisa = null,
        public ?string $note = null,
        public ?PaymentMethod $method = null,
        public bool $autoProcess = true,
    ) {}
}
