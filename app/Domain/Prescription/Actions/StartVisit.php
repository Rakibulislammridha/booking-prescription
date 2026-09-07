<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Actions;

use App\Domain\Prescription\Enums\VisitStatus;
use App\Domain\Prescription\Enums\VisitType;
use App\Domain\Prescription\Exceptions\SerialHasNoPatient;
use App\Domain\Prescription\Services\PrescriptionAuditor;
use App\Domain\Shared\Actor;
use App\Models\Tenant\Appointment;
use App\Models\Tenant\Patient;
use App\Models\Tenant\Serial;
use App\Models\Tenant\Visit;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Opens the clinical encounter for a called serial — idempotent (one visit per serial, partial unique index).
 * Runs from the SerialCalled listener and from the doctor screen's "open prescription" (PRESCRIPTION.md §1.1).
 */
final class StartVisit
{
    public function __construct(private readonly PrescriptionAuditor $auditor) {}

    public function handle(Serial $serial, Actor $actor, string $source = 'serial_called'): Visit
    {
        $existing = Visit::query()->where('serial_id', $serial->id)->first();

        if ($existing !== null) {
            return $existing;
        }

        if ($serial->patient_id === null) {
            throw new SerialHasNoPatient($serial->id);
        }

        $session = $serial->sessionInstance;
        $appointment = $serial->appointment_id !== null && class_exists(Appointment::class) ? Appointment::query()->find($serial->appointment_id) : null;
        $type = VisitType::Opd;

        if ($appointment !== null) {
            $type = (bool) $appointment->getAttribute('is_telemedicine') ? VisitType::Telemedicine
                : (self::enumValue($appointment->getAttribute('type')) === 'followup' ? VisitType::Followup : VisitType::Opd);
        }

        try {
            return DB::transaction(function () use ($serial, $session, $appointment, $type, $source): Visit {
                $visit = new Visit;
                $visit->fill([
                    'appointment_id' => $appointment?->id,
                    'serial_id' => $serial->id,
                    'session_instance_id' => $serial->session_instance_id,
                    'patient_id' => $serial->patient_id,
                    'doctor_id' => $session->doctor_id,
                    'branch_id' => $session->branch_id,
                    'type' => $type,
                    'status' => VisitStatus::Open,
                    'started_at' => now(),
                ]);
                $visit->save();

                $this->bumpPatient((int) $serial->patient_id);
                $this->auditor->visitStarted($visit, $source);

                return $visit;
            });
        } catch (QueryException $e) {
            $again = Visit::query()->where('serial_id', $serial->id)->first();   // lost a race: the unique index held

            if ($again !== null) {
                return $again;
            }

            throw $e;
        }
    }

    private static function enumValue(mixed $value): string
    {
        return $value instanceof \BackedEnum ? (string) $value->value : (string) $value;
    }

    private function bumpPatient(int $patientId): void
    {
        $patient = Patient::query()->find($patientId);

        if ($patient !== null) {
            $patient->forceFill(['last_visit_at' => now(), 'visit_count' => $patient->visit_count + 1])->save();
        }
    }
}
