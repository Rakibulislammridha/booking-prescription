<?php

declare(strict_types=1);

namespace App\Http\Resources\Clinic;

use App\Models\Tenant\Holiday;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Holiday */
final class HolidayResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'branch_id' => $this->branch_id,
            'branch_name' => $this->whenLoaded('branch', fn () => $this->branch?->name),
            'holiday_date' => $this->holiday_date->toDateString(),
            'name' => $this->name,
            'name_bn' => $this->name_bn,
        ];
    }
}
