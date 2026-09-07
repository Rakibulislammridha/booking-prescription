<?php

declare(strict_types=1);

namespace App\Domain\Patients\Actions;

use App\Domain\Patients\Data\PatientData;
use App\Domain\Patients\Exceptions\DuplicatePatient;
use App\Domain\Shared\Actor;
use App\Models\Tenant\Patient;
use App\Models\Tenant\PatientRelation;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Edits demographics. A mobile change moves the person to another household: they become that number's owner
 * when it is unused, otherwise a dependent of its owner; their own dependents keep pointing at them.
 */
final class UpdatePatient
{
    public function handle(Patient $patient, PatientData $data, Actor $actor): Patient
    {
        return DB::transaction(function () use ($patient, $data): Patient {
            $attributes = $data->toAttributes();
            unset($attributes['source']);
            $mobileChanged = $attributes['mobile'] !== $patient->mobile;

            if ($mobileChanged) {
                $owner = Patient::query()->where('mobile', $attributes['mobile'])->where('is_mobile_owner', true)->whereKeyNot($patient->id)->first();
                $attributes['is_mobile_owner'] = $owner === null;

                PatientRelation::query()->where('dependent_patient_id', $patient->id)->delete();

                if ($owner !== null) {
                    PatientRelation::query()->create(['primary_patient_id' => $owner->id, 'dependent_patient_id' => $patient->id, 'relation' => $data->relation]);
                }
            }

            try {
                $patient->fill($attributes)->save();
            } catch (UniqueConstraintViolationException) {
                throw new DuplicatePatient;
            }

            return $patient->refresh();
        });
    }
}
