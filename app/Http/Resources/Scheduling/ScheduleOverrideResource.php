<?php

declare(strict_types=1);

namespace App\Http\Resources\Scheduling;

use App\Models\Tenant\ScheduleOverride;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin ScheduleOverride */
final class ScheduleOverrideResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'doctor_id' => $this->doctor_id,
            'branch_id' => $this->branch_id,
            'override_date' => $this->override_date->toDateString(),
            'session_code' => $this->session_code,
            'type' => $this->type->value,
            'delay_minutes' => $this->delay_minutes,
            'new_start_time' => $this->new_start_time === null ? null : substr($this->new_start_time, 0, 5),
            'new_end_time' => $this->new_end_time === null ? null : substr($this->new_end_time, 0, 5),
            'new_max_serials' => $this->new_max_serials,
            'new_online_quota' => $this->new_online_quota,
            'new_counter_quota' => $this->new_counter_quota,
            'new_buffer_quota' => $this->new_buffer_quota,
            'reason' => $this->reason,
            'notify_patients' => $this->notify_patients,
            'applied_at' => $this->applied_at?->toIso8601ZuluString(),
        ];
    }
}
