<?php

declare(strict_types=1);

namespace App\Http\Resources\Billing;

use App\Domain\Shared\Money;
use App\Models\Tenant\Refund;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Refund */
final class RefundResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'amount' => Money::bdt($this->amount_paisa),
            'amount_paisa' => $this->amount_paisa,
            'method' => $this->method->value,
            'status' => $this->status->value,
            'reason_code' => $this->reason_code->value,
            'reason_note' => $this->reason_note,
            'processed_at' => $this->processed_at?->toIso8601ZuluString(),
            'payment_public_id' => $this->whenLoaded('payment', fn () => $this->payment->public_id),
        ];
    }
}
