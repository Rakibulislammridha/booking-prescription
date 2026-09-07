<?php

declare(strict_types=1);

namespace App\Http\Resources\Billing;

use App\Models\Tenant\CashShift;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin CashShift */
final class CashShiftResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'opened_at' => $this->opened_at->toIso8601ZuluString(),
            'closed_at' => $this->closed_at?->toIso8601ZuluString(),
            'opening_float_paisa' => $this->opening_float_paisa,
            'expected_cash_paisa' => $this->expected_cash_paisa,
            'counted_cash_paisa' => $this->counted_cash_paisa,
            'variance_paisa' => $this->variance_paisa,
            'card_total_paisa' => $this->card_total_paisa,
            'mobile_money_total_paisa' => $this->mobile_money_total_paisa,
            'closing_note' => $this->closing_note,
            'user' => $this->whenLoaded('user', fn () => ['public_id' => $this->user->public_id, 'name' => $this->user->name]),
            'branch' => $this->whenLoaded('branch', fn () => ['public_id' => $this->branch->public_id, 'name' => $this->branch->name]),
        ];
    }
}
