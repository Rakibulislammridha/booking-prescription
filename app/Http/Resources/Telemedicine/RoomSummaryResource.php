<?php

declare(strict_types=1);

namespace App\Http\Resources\Telemedicine;

use App\Models\Tenant\TelemedicineRoom;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row of the doctor's telemedicine board. Deliberately small: the board is a list of "who is waiting", not a
 * second patient record.
 *
 * @mixin TelemedicineRoom
 */
final class RoomSummaryResource extends JsonResource
{
    public static $wrap = null;

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        /** @var TelemedicineRoom $room */
        $room = $this->resource;
        $appointment = $room->appointment;
        $serial = $appointment->serial;

        return [
            'room' => $room->room_name,
            'status' => $room->status->value,
            'scheduled_at' => $room->scheduled_at->toIso8601String(),
            'opened_at' => $room->opened_at?->toIso8601String(),
            'patient' => [
                'public_id' => $appointment->patient->public_id,
                'name' => $appointment->patient->name,
                'code' => $appointment->patient->patient_code,
            ],
            'doctor' => [
                'public_id' => $appointment->doctor->public_id,
                'name' => $appointment->doctor->name,
            ],
            'serial' => $serial === null ? null : ['code' => $serial->display_code, 'status' => $serial->status->value],
            'fee_paisa' => $appointment->fee_paisa,
            'payment_status' => $appointment->payment_status->value,
        ];
    }
}
