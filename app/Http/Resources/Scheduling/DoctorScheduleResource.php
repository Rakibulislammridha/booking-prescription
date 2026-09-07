<?php

declare(strict_types=1);

namespace App\Http\Resources\Scheduling;

use App\Models\Tenant\DoctorSchedule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin DoctorSchedule */
final class DoctorScheduleResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'doctor_id' => $this->doctor_id,
            'branch_id' => $this->branch_id,
            'weekday' => $this->weekday,
            'session_code' => $this->session_code,
            'session_label' => $this->session_label,
            'start_time' => substr($this->start_time, 0, 5),
            'end_time' => substr($this->end_time, 0, 5),
            'mode' => $this->mode->value,
            'slot_minutes' => $this->slot_minutes,
            'max_serials' => $this->max_serials,
            'online_quota' => $this->online_quota,
            'counter_quota' => $this->counter_quota,
            'buffer_quota' => $this->buffer_quota,
            'avg_consult_minutes' => $this->avg_consult_minutes,
            'fee_new_paisa' => $this->fee_new_paisa,
            'fee_followup_paisa' => $this->fee_followup_paisa,
            'auto_noshow_after' => $this->auto_noshow_after,
            'works_on_holidays' => $this->works_on_holidays,
            'effective_from' => $this->effective_from->toDateString(),
            'effective_to' => $this->effective_to?->toDateString(),
            'is_active' => $this->is_active,
        ];
    }
}
