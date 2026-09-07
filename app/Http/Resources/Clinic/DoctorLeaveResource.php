<?php

declare(strict_types=1);

namespace App\Http\Resources\Clinic;

use App\Models\Tenant\DoctorLeave;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin DoctorLeave */
final class DoctorLeaveResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'doctor_id' => $this->doctor_id,
            'doctor_name' => $this->whenLoaded('doctor', fn () => $this->doctor?->name),
            'branch_id' => $this->branch_id,
            'branch_name' => $this->whenLoaded('branch', fn () => $this->branch?->name),
            'starts_on' => $this->starts_on->toDateString(),
            'ends_on' => $this->ends_on->toDateString(),
            'type' => $this->type->value,
            'reason' => $this->reason,
            'notify_patients' => $this->notify_patients,
            'notified_at' => $this->notified_at?->toIso8601ZuluString(),
            'is_cancelled' => $this->is_cancelled,
        ];
    }
}
