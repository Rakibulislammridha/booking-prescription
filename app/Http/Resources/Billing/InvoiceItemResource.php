<?php

declare(strict_types=1);

namespace App\Http\Resources\Billing;

use App\Domain\Shared\Money;
use App\Models\Tenant\InvoiceItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin InvoiceItem */
final class InvoiceItemResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sort_order' => $this->sort_order,
            'type' => $this->type->value,
            'description' => $this->description,
            'quantity' => $this->quantity,
            'unit_price' => Money::bdt($this->unit_price_paisa),
            'line_total' => Money::bdt($this->line_total_paisa),
            'unit_price_paisa' => $this->unit_price_paisa,
            'line_total_paisa' => $this->line_total_paisa,
            'doctor_share_paisa' => $this->doctor_share_paisa,
            'clinic_share_paisa' => $this->clinic_share_paisa,
        ];
    }
}
