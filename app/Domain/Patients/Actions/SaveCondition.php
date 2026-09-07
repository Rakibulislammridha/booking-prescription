<?php

declare(strict_types=1);

namespace App\Domain\Patients\Actions;

use App\Domain\Patients\Data\ConditionData;
use App\Domain\Shared\Actor;
use App\Models\Tenant\Patient;
use App\Models\Tenant\PatientCondition;

final class SaveCondition
{
    public function handle(Patient $patient, ConditionData $data, Actor $actor, ?PatientCondition $existing = null): PatientCondition
    {
        if ($existing !== null) {
            $existing->fill($data->toAttributes())->save();

            return $existing->refresh();
        }

        return PatientCondition::query()->create($data->toAttributes() + [
            'patient_id' => $patient->id,
            'recorded_by_user_id' => $actor->userId,
        ]);
    }
}
