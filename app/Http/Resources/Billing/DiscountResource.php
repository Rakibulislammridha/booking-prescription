<?php

declare(strict_types=1);

namespace App\Http\Resources\Billing;

use App\Domain\Shared\Money;
use App\Models\Tenant\Discount;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Discount */
final class DiscountResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'value' => $this->value,
            'amount' => Money::bdt($this->amount_paisa),
            'amount_paisa' => $this->amount_paisa,
            'reason_code' => $this->reason_code->value,
            'note' => $this->note,
            'approved' => $this->approved_by_user_id !== null,
        ];
    }
}
