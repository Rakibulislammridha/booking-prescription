<?php

declare(strict_types=1);

namespace App\Http\Resources\Scheduling;

use App\Http\Resources\Serials\SerialResource;
use App\Models\Tenant\SessionInstance;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Session instance for the panel pages and the JSON session endpoints. `remaining` (CapacityService) is attached by
 * the controller via `$resource->additional`-style attribute `remaining` on the model when it was computed.
 *
 * @mixin SessionInstance
 */
final class SessionInstanceResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'session_code' => $this->session_code,
            'session_date' => $this->session_date->toDateString(),
            'status' => $this->status->value,
            'mode' => $this->mode->value,
            'slot_minutes' => $this->slot_minutes,
            'planned_start_at' => $this->planned_start_at->toIso8601ZuluString(),
            'planned_end_at' => $this->planned_end_at->toIso8601ZuluString(),
            'actual_start_at' => $this->actual_start_at?->toIso8601ZuluString(),
            'actual_end_at' => $this->actual_end_at?->toIso8601ZuluString(),
            'delay_minutes' => $this->delay_minutes,
            'pause_seconds' => $this->pause_seconds,
            'max_serials' => $this->max_serials,
            'online_quota' => $this->online_quota,
            'counter_quota' => $this->counter_quota,
            'buffer_quota' => $this->buffer_quota,
            'avg_consult_seconds' => $this->avg_consult_seconds,
            'consult_samples' => $this->consult_samples,
            'auto_noshow_after' => $this->auto_noshow_after,
            'now_serving_serial_id' => $this->now_serving_serial_id,
            'counts' => [
                'booked' => $this->booked_count,
                'checked_in' => $this->checked_in_count,
                'in_consultation' => $this->in_consultation_count,
                'completed' => $this->completed_count,
                'no_show' => $this->no_show_count,
                'cancelled' => $this->cancelled_count,
                'postponed' => $this->postponed_count,
            ],
            'version' => $this->version,
            'cancel_reason' => $this->cancel_reason,
            'doctor' => $this->whenLoaded('doctor', fn () => ['public_id' => $this->doctor->public_id, 'slug' => $this->doctor->slug, 'name' => $this->doctor->name, 'name_bn' => $this->doctor->name_bn, 'room' => $this->doctor->room_label]),
            'branch' => $this->whenLoaded('branch', fn () => ['public_id' => $this->branch->public_id, 'name' => $this->branch->name, 'code' => $this->branch->code, 'slug' => $this->branch->slug]),
            'remaining' => $this->when($this->resource->getAttribute('remaining') !== null, fn () => $this->resource->getAttribute('remaining')),
            'serials' => SerialResource::collection($this->whenLoaded('serials')),
        ];
    }
}
