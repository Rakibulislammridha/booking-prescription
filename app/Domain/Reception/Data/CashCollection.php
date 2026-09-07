<?php

declare(strict_types=1);

namespace App\Domain\Reception\Data;

use App\Domain\Booking\Enums\PaymentStatus;

/** What a CashCollector recorded: the receipt number the slip prints and the appointment's new payment status. */
final readonly class CashCollection
{
    public function __construct(
        public ?string $paymentPublicId,
        public string $receiptNo,
        public int $amountPaisa,
        public PaymentStatus $paymentStatus,
        public bool $duplicate = false,   // the same receipt was already recorded (idempotent replay)
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['public_id' => $this->paymentPublicId, 'receipt_no' => $this->receiptNo, 'amount_paisa' => $this->amountPaisa, 'payment_status' => $this->paymentStatus->value, 'duplicate' => $this->duplicate];
    }
}
