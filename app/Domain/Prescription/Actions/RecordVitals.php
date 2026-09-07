<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Actions;

use App\Domain\Prescription\Data\VitalsData;
use App\Domain\Prescription\Services\PrescriptionAuditor;
use App\Domain\Shared\Actor;
use App\Models\Tenant\Visit;
use App\Models\Tenant\Vital;

/** Compounder (or doctor) records a vitals set for the visit; several rows allowed; BMI computed here (§4.2). */
final class RecordVitals
{
    public function __construct(private readonly PrescriptionAuditor $auditor) {}

    public function handle(Visit $visit, VitalsData $data, Actor $actor, bool $byDoctor = false): Vital
    {
        $vital = new Vital;
        $vital->fill($data->toAttributes() + [
            'visit_id' => $visit->id,
            'patient_id' => $visit->patient_id,
            'recorded_by_user_id' => $actor->userId,
            'recorded_at' => now(),
            'edited_by_doctor' => $byDoctor,
            'reviewed_by_doctor_at' => $byDoctor ? now() : null,
        ]);
        $vital->save();
        $this->auditor->vitalsRecorded($vital);

        return $vital;
    }
}
