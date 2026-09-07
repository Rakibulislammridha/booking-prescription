<?php

declare(strict_types=1);

namespace App\Domain\Patients\Actions;

use App\Domain\Patients\Data\MedicationData;
use App\Domain\Shared\Actor;
use App\Models\Tenant\Patient;
use App\Models\Tenant\PatientMedication;

final class SaveMedication
{
    public function handle(Patient $patient, MedicationData $data, Actor $actor, ?PatientMedication $existing = null): PatientMedication
    {
        if ($existing !== null) {
            $existing->fill($data->toAttributes())->save();

            return $existing->refresh();
        }

        return PatientMedication::query()->create($data->toAttributes() + ['patient_id' => $patient->id]);
    }
}
