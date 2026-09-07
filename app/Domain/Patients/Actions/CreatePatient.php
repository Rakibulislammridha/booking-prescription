<?php

declare(strict_types=1);

namespace App\Domain\Patients\Actions;

use App\Domain\Patients\Data\PatientData;
use App\Domain\Patients\Events\PatientCreated;
use App\Domain\Patients\Exceptions\DuplicatePatient;
use App\Domain\Shared\Actor;
use App\Models\Tenant\Patient;
use App\Models\Tenant\PatientRelation;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Desk / panel registration. The household rule (SCHEMA §5.4) is applied automatically: the first person on a
 * mobile is the owner; anyone later is a dependent of that owner (or of `primaryPublicId` when the form names one).
 */
final class CreatePatient
{
    public function handle(PatientData $data, Actor $actor): Patient
    {
        return DB::transaction(function () use ($data, $actor): Patient {
            $primary = $this->resolvePrimary($data);

            [$dob] = $data->resolvedDob();
            $exists = Patient::query()
                ->where('mobile', $data->mobile)
                ->whereRaw('name_normalized = lower(btrim(?))', [$data->name])
                ->whereRaw("coalesce(dob, date '0001-01-01') = coalesce(?, date '0001-01-01')", [$dob?->toDateString()])
                ->exists();

            if ($exists) {
                throw new DuplicatePatient;
            }

            try {
                $patient = Patient::query()->create($data->toAttributes() + [
                    'is_mobile_owner' => $primary === null,
                    'registered_by_user_id' => $data->registeredByUserId ?? $actor->userId,
                ]);
            } catch (UniqueConstraintViolationException) {
                throw new DuplicatePatient;
            }

            if ($primary !== null) {
                PatientRelation::query()->create([
                    'primary_patient_id' => $primary->id,
                    'dependent_patient_id' => $patient->id,
                    'relation' => $data->relation,
                ]);
            }

            DB::afterCommit(fn () => event(new PatientCreated($patient)));

            return $patient;
        });
    }

    private function resolvePrimary(PatientData $data): ?Patient
    {
        if ($data->primaryPublicId !== null) {
            $named = Patient::query()->wherePublicId($data->primaryPublicId)->firstOrFail();

            // Follow the chain: a dependent's primary is the real owner.
            return $named->primaryRelation->primary ?? $named;
        }

        return Patient::query()->where('mobile', $data->mobile)->where('is_mobile_owner', true)->first();
    }
}
