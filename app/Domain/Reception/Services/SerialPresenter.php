<?php

declare(strict_types=1);

namespace App\Domain\Reception\Services;

use App\Domain\Patients\Services\MobileNumber;
use App\Http\Resources\Serials\SerialResource;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Patient;
use App\Models\Tenant\Serial;
use Illuminate\Http\Request;

/**
 * The desk's serial shape: SerialResource (S) + patient {public_id, name, mobile_masked, age_text, sex} +
 * appointment {public_id, type, channel, fee_paisa, payment_status}. Used by the board JSON, the bootstrap and every
 * accepted replay result, so the PWA caches one shape (OFFLINE §5.1).
 */
final class SerialPresenter
{
    /** @return array<string, mixed> */
    public function present(Serial $serial, ?Patient $patient = null, ?Appointment $appointment = null): array
    {
        $patient ??= $serial->patient_id === null ? null : Patient::query()->find($serial->patient_id);
        $appointment ??= $serial->appointment_id === null ? null : Appointment::query()->find($serial->appointment_id);

        $base = (new SerialResource($serial->loadMissing('sessionInstance')))->toArray(app(Request::class));

        return array_merge($base, [
            'patient' => $patient === null ? null : self::patient($patient),
            'appointment' => $appointment === null ? null : [
                'public_id' => $appointment->public_id,
                'type' => $appointment->type->value,
                'channel' => $appointment->channel->value,
                'status' => $appointment->status->value,
                'fee_paisa' => $appointment->fee_paisa,
                'list_fee_paisa' => $appointment->list_fee_paisa,
                'fee_rule' => $appointment->fee_rule->value,
                'payment_status' => $appointment->payment_status->value,
            ],
        ]);
    }

    /** @return array{public_id: string, name: string, mobile_masked: string, age_text: string|null, sex: string|null, patient_code: string} */
    public static function patient(Patient $patient): array
    {
        return [
            'public_id' => $patient->public_id,
            'name' => $patient->name,
            'mobile_masked' => MobileNumber::mask($patient->mobile),
            'age_text' => $patient->age_text,
            'sex' => $patient->gender?->value,
            'patient_code' => $patient->patient_code,
        ];
    }
}
