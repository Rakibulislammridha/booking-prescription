<?php

declare(strict_types=1);

namespace App\Domain\Billing\Data;

use App\Models\Tenant\Invoice;
use App\Models\Tenant\Payment;

/** `duplicate = true` means the request was a replay and NO new money was recorded. */
final readonly class PaymentResult
{
    public function __construct(
        public Payment $payment,
        public Invoice $invoice,
        public bool $duplicate = false,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'public_id' => $this->payment->public_id,
            'receipt_no' => $this->payment->receipt_number,
            'amount_paisa' => $this->payment->amount_paisa,
            'method' => $this->payment->method->value,
            'status' => $this->payment->status->value,
            'duplicate' => $this->duplicate,
            'invoice' => [
                'public_id' => $this->invoice->public_id,
                'number' => $this->invoice->number,
                'total_paisa' => $this->invoice->total_paisa,
                'paid_paisa' => $this->invoice->paid_paisa,
                'due_paisa' => $this->invoice->due_paisa,
                'status' => $this->invoice->status->value,
            ],
        ];
    }
}
