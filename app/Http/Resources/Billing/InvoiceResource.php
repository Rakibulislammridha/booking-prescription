<?php

declare(strict_types=1);

namespace App\Http\Resources\Billing;

use App\Domain\Shared\Money;
use App\Models\Tenant\Invoice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The bill on the wire (CONVENTIONS §13): identifiers are public ids, money is `{paisa, formatted}`.
 *
 * @mixin Invoice
 */
final class InvoiceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'number' => $this->number,
            'status' => $this->status->value,
            'subtotal' => Money::bdt($this->subtotal_paisa),
            'discount' => Money::bdt($this->discount_paisa),
            'coupon_discount' => Money::bdt($this->coupon_discount_paisa),
            'vat' => Money::bdt($this->vat_paisa),
            'total' => Money::bdt($this->total_paisa),
            'paid' => Money::bdt($this->paid_paisa),
            'due' => Money::bdt($this->due_paisa),
            'subtotal_paisa' => $this->subtotal_paisa,
            'discount_paisa' => $this->discount_paisa,
            'coupon_discount_paisa' => $this->coupon_discount_paisa,
            'vat_paisa' => $this->vat_paisa,
            'total_paisa' => $this->total_paisa,
            'paid_paisa' => $this->paid_paisa,
            'due_paisa' => $this->due_paisa,
            'issued_at' => $this->issued_at?->toIso8601ZuluString(),
            'paid_at' => $this->paid_at?->toIso8601ZuluString(),
            'voided_at' => $this->voided_at?->toIso8601ZuluString(),
            'void_reason' => $this->void_reason,
            'notes' => $this->notes,
            'patient' => $this->whenLoaded('patient', fn () => [
                'public_id' => $this->patient->public_id,
                'name' => $this->patient->name,
                'patient_code' => $this->patient->patient_code,
                'mobile_local' => $this->patient->mobile_local,
            ]),
            'doctor' => $this->whenLoaded('doctor', fn () => $this->doctor === null ? null : [
                'public_id' => $this->doctor->public_id,
                'name' => $this->doctor->name,
                'name_bn' => $this->doctor->name_bn,
            ]),
            'branch' => $this->whenLoaded('branch', fn () => ['public_id' => $this->branch->public_id, 'name' => $this->branch->name]),
            'appointment' => $this->whenLoaded('appointment', fn () => $this->appointment === null ? null : [
                'public_id' => $this->appointment->public_id,
                'type' => $this->appointment->type->value,
                'fee_rule' => $this->appointment->fee_rule->value,
                'fee_rule_reason' => $this->appointment->fee_rule_reason,
                'scheduled_date' => $this->appointment->scheduled_date?->toDateString(),
            ]),
            'items' => InvoiceItemResource::collection($this->whenLoaded('items')),
            'payments' => PaymentResource::collection($this->whenLoaded('payments')),
            'refunds' => RefundResource::collection($this->whenLoaded('refunds')),
            'discounts' => DiscountResource::collection($this->whenLoaded('discounts')),
        ];
    }
}
