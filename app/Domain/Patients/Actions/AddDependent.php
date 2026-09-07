<?php

declare(strict_types=1);

namespace App\Domain\Patients\Actions;

use App\Domain\Patients\Data\PatientData;
use App\Domain\Shared\Actor;
use App\Models\Tenant\Patient;

/** "New family member" on an existing patient: same mobile, linked to the household's owner. */
final class AddDependent
{
    public function __construct(private readonly CreatePatient $create) {}

    public function handle(Patient $primary, PatientData $data, Actor $actor): Patient
    {
        $owner = $primary->primaryRelation->primary ?? $primary;

        $dependentData = new PatientData(
            name: $data->name,
            mobile: $owner->mobile,
            gender: $data->gender,
            dob: $data->dob,
            ageYears: $data->ageYears,
            bloodGroup: $data->bloodGroup,
            email: $data->email,
            address: $data->address ?? $owner->address,
            district: $data->district ?? $owner->district,
            nationalId: $data->nationalId,
            guardianName: $data->guardianName ?? $owner->name,
            preferredLanguage: $data->preferredLanguage,
            notes: $data->notes,
            tags: $data->tags,
            registeredBranchId: $data->registeredBranchId ?? $owner->registered_branch_id,
            registeredByUserId: $data->registeredByUserId ?? $actor->userId,
            source: $data->source,
            isActive: true,
            primaryPublicId: $owner->public_id,
            relation: $data->relation,
        );

        return $this->create->handle($dependentData, $actor);
    }
}
