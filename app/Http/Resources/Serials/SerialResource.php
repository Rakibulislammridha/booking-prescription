<?php

declare(strict_types=1);

namespace App\Http\Resources\Serials;

use App\Models\Tenant\Serial;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * SERIAL_ENGINE §16: the one serial shape for JSON endpoints, the reception bootstrap and the Inertia board props.
 * `patient` is filled by the Patients module's resource once it ships (the engine only knows patient_id); `eta` is the
 * Queue module's number and stays null here.
 *
 * @mixin Serial
 */
final class SerialResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'display_code' => $this->display_code,
            'number' => $this->number,
            'position' => $this->position,
            'status' => $this->status->value,
            'priority' => $this->priority->value,
            'source' => $this->source->value,
            'pool' => $this->pool->value,
            'patient_id' => $this->patient_id,
            'patient' => null,
            'appointment_id' => $this->appointment_id,
            'slot_start_at' => $this->slot_start_at?->toIso8601ZuluString(),
            'booked_at' => $this->booked_at->toIso8601ZuluString(),
            'checked_in_at' => $this->checked_in_at?->toIso8601ZuluString(),
            'called_at' => $this->called_at?->toIso8601ZuluString(),
            'completed_at' => $this->completed_at?->toIso8601ZuluString(),
            'no_show_at' => $this->no_show_at?->toIso8601ZuluString(),
            'cancelled_at' => $this->cancelled_at?->toIso8601ZuluString(),
            'cancel_reason_code' => $this->cancel_reason_code?->value,
            'passed_count' => $this->passed_count,
            'skip_count' => $this->skip_count,
            'eta' => null,
            'session' => $this->whenLoaded('sessionInstance', fn () => [
                'public_id' => $this->sessionInstance->public_id,
                'code' => $this->sessionInstance->session_code,
                'date' => $this->sessionInstance->session_date->toDateString(),
            ]),
        ];
    }
}
