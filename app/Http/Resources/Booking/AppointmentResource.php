<?php

declare(strict_types=1);

namespace App\Http\Resources\Booking;

use App\Domain\Shared\Money;
use App\Models\Tenant\Appointment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The booking on the wire (site confirmation, desk dialogs, public API).
 *
 * @mixin Appointment
 */
final class AppointmentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'type' => $this->type->value,
            'channel' => $this->channel->value,
            'status' => $this->status->value,
            'scheduled_date' => $this->scheduled_date?->toDateString(),
            'slot_start_at' => $this->slot_start_at?->toIso8601ZuluString(),
            'fee' => Money::bdt($this->fee_paisa),
            'list_fee' => Money::bdt($this->list_fee_paisa),
            'fee_rule' => $this->fee_rule->value,
            'fee_rule_reason' => $this->fee_rule_reason,
            'payment_status' => $this->payment_status->value,
            'notes' => $this->notes,
            'confirmed_at' => $this->confirmed_at?->toIso8601ZuluString(),
            'cancelled_at' => $this->cancelled_at?->toIso8601ZuluString(),
            'cancel_reason_code' => $this->cancel_reason_code?->value,
            'patient' => $this->whenLoaded('patient', fn () => ['public_id' => $this->patient->public_id, 'name' => $this->patient->name, 'mobile_local' => $this->patient->mobile_local]),
            'doctor' => $this->whenLoaded('doctor', fn () => ['public_id' => $this->doctor->public_id, 'slug' => $this->doctor->slug, 'name' => $this->doctor->name, 'name_bn' => $this->doctor->name_bn, 'room' => $this->doctor->room_label]),
            'session' => $this->whenLoaded('sessionInstance', fn () => $this->sessionInstance === null ? null : ['public_id' => $this->sessionInstance->public_id, 'code' => $this->sessionInstance->session_code, 'date' => $this->sessionInstance->session_date->toDateString(), 'planned_start_at' => $this->sessionInstance->planned_start_at->toIso8601ZuluString(), 'status' => $this->sessionInstance->status->value]),
            'serial' => $this->whenLoaded('serial', fn () => $this->serial === null ? null : ['public_id' => $this->serial->public_id, 'display_code' => $this->serial->display_code, 'number' => $this->serial->number, 'status' => $this->serial->status->value, 'position' => $this->serial->position]),
        ];
    }
}
