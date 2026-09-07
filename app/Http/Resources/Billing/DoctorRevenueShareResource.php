<?php

declare(strict_types=1);

namespace App\Http\Resources\Billing;

use App\Models\Tenant\DoctorRevenueShare;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin DoctorRevenueShare */
final class DoctorRevenueShareResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'item_type' => $this->item_type->value,
            'share_type' => $this->share_type->value,
            'share_value' => $this->share_value,
            'effective_from' => $this->effective_from->toDateString(),
            'effective_to' => $this->effective_to?->toDateString(),
            'is_active' => $this->is_active,
            'doctor' => $this->whenLoaded('doctor', fn () => ['public_id' => $this->doctor->public_id, 'name' => $this->doctor->name]),
            'branch' => $this->whenLoaded('branch', fn () => $this->branch === null ? null : ['public_id' => $this->branch->public_id, 'name' => $this->branch->name]),
        ];
    }
}
