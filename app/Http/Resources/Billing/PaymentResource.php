<?php

declare(strict_types=1);

namespace App\Http\Resources\Billing;

use App\Domain\Shared\Money;
use App\Models\Tenant\Payment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * A payment on the wire. The gateway payload is deliberately NOT exposed: it can carry the patient's masked
 * wallet number and the merchant's own references.
 *
 * @mixin Payment
 */
final class PaymentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'receipt_number' => $this->receipt_number,
            'method' => $this->method->value,
            'status' => $this->status->value,
            'amount' => Money::bdt($this->amount_paisa),
            'refunded' => Money::bdt($this->refunded_paisa),
            'amount_paisa' => $this->amount_paisa,
            'refunded_paisa' => $this->refunded_paisa,
            'refundable_paisa' => $this->refundablePaisa(),
            'gateway' => $this->gateway?->value,
            'gateway_txn_id' => $this->gateway_txn_id,
            'paid_at' => $this->paid_at?->toIso8601ZuluString(),
            'failed_reason' => $this->failed_reason,
            'received_by' => $this->whenLoaded('receivedBy', fn () => $this->receivedBy === null ? null : ['public_id' => $this->receivedBy->public_id, 'name' => $this->receivedBy->name]),
        ];
    }
}
