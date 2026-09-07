<?php

declare(strict_types=1);

namespace App\Domain\Patients\Actions;

use App\Domain\Patients\Data\AllergyData;
use App\Domain\Shared\Actor;
use App\Models\Tenant\Patient;
use App\Models\Tenant\PatientAllergy;

/** Create or update one allergy row; the Auditable trait writes the create/update audit entry. */
final class SaveAllergy
{
    public function handle(Patient $patient, AllergyData $data, Actor $actor, ?PatientAllergy $existing = null): PatientAllergy
    {
        if ($existing !== null) {
            $existing->fill($data->toAttributes())->save();

            return $existing->refresh();
        }

        return PatientAllergy::query()->create($data->toAttributes() + [
            'patient_id' => $patient->id,
            'recorded_by_user_id' => $actor->userId,
        ]);
    }
}
