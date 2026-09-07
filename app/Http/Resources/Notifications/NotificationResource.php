<?php

declare(strict_types=1);

namespace App\Http\Resources\Notifications;

use App\Domain\Notifications\Drivers\LogChannelDriver;
use App\Domain\Notifications\Enums\NotificationEvent;
use App\Models\Tenant\Notification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One outbound-log row. Two deliberate redactions:
 *  - an `otp` body is never returned (it contains a live login code; CONVENTIONS §11);
 *  - the recipient is masked, because the log is a staff screen and the full mobile is already on the patient record.
 *
 * @mixin Notification
 */
final class NotificationResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $isOtp = $this->event_key === NotificationEvent::Otp;

        return [
            'id' => $this->id,
            'event_key' => $this->event_key->value,
            'channel' => $this->channel->value,
            'status' => $this->status->value,
            'recipient' => LogChannelDriver::mask($this->recipient),
            'locale' => $this->locale->value,
            'subject' => $this->subject,
            'body' => $isOtp ? null : $this->body,
            'body_redacted' => $isOtp,
            'patient' => $this->whenLoaded('patient', fn () => $this->patient === null ? null : ['public_id' => $this->patient->public_id, 'name' => $this->patient->name, 'patient_code' => $this->patient->patient_code]),
            'serial_id' => $this->serial_id,
            'attempts' => $this->attempts,
            'segments' => $this->segments,
            'cost_paisa' => $this->cost_paisa,
            'last_error' => $this->last_error,
            'scheduled_for' => $this->scheduled_for?->toIso8601ZuluString(),
            'sent_at' => $this->sent_at?->toIso8601ZuluString(),
            'delivered_at' => $this->delivered_at?->toIso8601ZuluString(),
            'created_at' => $this->created_at->toIso8601ZuluString(),
        ];
    }
}
