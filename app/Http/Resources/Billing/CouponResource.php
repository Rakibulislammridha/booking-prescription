<?php

declare(strict_types=1);

namespace App\Http\Resources\Billing;

use App\Models\Tenant\Coupon;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Coupon */
final class CouponResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'type' => $this->type->value,
            'value' => $this->value,
            'max_discount_paisa' => $this->max_discount_paisa,
            'min_invoice_paisa' => $this->min_invoice_paisa,
            'max_uses' => $this->max_uses,
            'max_uses_per_patient' => $this->max_uses_per_patient,
            'uses_count' => $this->uses_count,
            'applies_to' => $this->applies_to,
            'valid_from' => $this->valid_from?->toIso8601ZuluString(),
            'valid_until' => $this->valid_until?->toIso8601ZuluString(),
            'is_active' => $this->is_active,
        ];
    }
}
