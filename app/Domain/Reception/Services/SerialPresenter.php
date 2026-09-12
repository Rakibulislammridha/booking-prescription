<?php

declare(strict_types=1);

namespace App\Domain\Reception\Services;

use App\Domain\Booking\Services\AdvancePaymentPolicy;
use App\Domain\Patients\Services\MobileNumber;
use App\Domain\Prescription\Data\IssuedPrescriptionRef;
use App\Domain\Prescription\Data\VitalsStatus;
use App\Domain\Prescription\Queries\IssuedPrescriptionQuery;
use App\Domain\Prescription\Queries\VitalsStatusQuery;
use App\Domain\Serials\Enums\SerialStatus;
use App\Http\Resources\Serials\SerialResource;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Patient;
use App\Models\Tenant\Serial;
use Illuminate\Http\Request;

/**
 * The desk's serial shape: SerialResource (S) + patient {public_id, name, mobile_masked, age_text, sex} +
 * appointment {public_id, type, channel, fee_paisa, payment_status, hold_expires_at} + vitals + prescription. Used
 * by the board JSON, the bootstrap and every accepted replay result, so the PWA caches one shape (OFFLINE §5.1).
 *
 * Three of those keys are about things the desk cannot see for itself:
 *
 * `vitals` answers "does this patient still need the compounder?" (BRIEF §5.G.2). It comes from the Prescription
 * module's own VitalsStatusQuery — Reception never reads `vitals`/`visits` — and is present only on the rows that
 * can have a reading at all (checked in / in consultation): a booked patient who has not arrived and a finished
 * one are not part of that question, and `null` says so rather than lying "not recorded".
 *
 * `prescription` answers "is there an issued prescription to print for this patient?" (BRIEF §5.G.4 — printed at
 * the desk too). Same module boundary (IssuedPrescriptionQuery), same honesty: it is the HANDLE of the latest
 * issued version — public id, verification code, version — and never the snapshot, so the board and the device
 * cache carry nothing clinical. Asked only for rows that can have an encounter at all (present or completed);
 * `null` everywhere else, and `null` on such a row when nothing has been issued.
 *
 * `appointment.hold_expires_at` is the deadline of an advance-payment hold (BRIEF §5.C): a `pending` booking whose
 * serial `booking:expire-holds` will release once the hold window passes. It is emitted only when the sweep would
 * actually take the number back (still pending, still unpaid), so a countdown on the board never runs against a
 * row that is not going anywhere.
 */
final class SerialPresenter
{
    private ?int $holdMinutes = null;

    public function __construct(
        private readonly VitalsStatusQuery $vitals,
        private readonly IssuedPrescriptionQuery $prescriptions,
        private readonly AdvancePaymentPolicy $holds,
    ) {}

    /**
     * Board rows this shape carries a vitals answer for; the board loads them in one query (BoardBuilder). The
     * patient has to be in the building (SerialStatus::isPresent) — the same rule SerialPolicy::recordVitals
     * enforces when the row's button is pressed.
     */
    public static function canHaveVitals(Serial $serial): bool
    {
        return $serial->status->isPresent();
    }

    /**
     * Board rows this shape carries a prescription handle for; the board loads them in one query (BoardBuilder).
     * An encounter exists only once the patient is in the building, and the sheet is most often wanted after the
     * consultation, so: present or completed. A booked, cancelled or no-show row has nothing to print.
     */
    public static function canHavePrescription(Serial $serial): bool
    {
        return $serial->status->isPresent() || $serial->status === SerialStatus::Completed;
    }

    /** @return array<string, mixed> */
    public function present(Serial $serial, ?Patient $patient = null, ?Appointment $appointment = null, ?VitalsStatus $vitals = null, ?IssuedPrescriptionRef $prescription = null): array
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
                'hold_expires_at' => $this->holdExpiresAt($appointment),
            ],
            'vitals' => self::canHaveVitals($serial)
                ? ($vitals ?? $this->vitals->forSerial($serial->id))->toArray()
                : null,
            'prescription' => self::canHavePrescription($serial)
                ? ($prescription ?? $this->prescriptions->forSerial($serial->id))->toArray()
                : null,
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

    /**
     * When `booking:expire-holds` will release this serial: `created_at` + the hold window, on the sweep's own
     * predicate (AdvancePaymentPolicy::isUnpaidHold). A hold that has been part-paid is pending but is NOT swept,
     * so it gets no deadline — the board shows it as held without a countdown it would be wrong about.
     */
    private function holdExpiresAt(Appointment $appointment): ?string
    {
        if (! $this->holds->isUnpaidHold($appointment)) {
            return null;
        }

        $this->holdMinutes ??= $this->holds->holdMinutes();

        return $appointment->created_at?->toImmutable()->addMinutes($this->holdMinutes)->toIso8601ZuluString();
    }
}
