<?php

declare(strict_types=1);

namespace App\Domain\Patients\Actions;

use App\Domain\Patients\Enums\PatientRelation as RelationType;
use App\Domain\Patients\Exceptions\DependentAlreadyLinked;
use App\Domain\Shared\Actor;
use App\Models\Tenant\Patient;
use App\Models\Tenant\PatientRelation;
use Illuminate\Support\Facades\DB;

/** Links an EXISTING patient under a primary (e.g. two separately registered people who share a home). */
final class LinkFamilyMember
{
    public function handle(Patient $primary, Patient $dependent, RelationType $relation, Actor $actor): PatientRelation
    {
        return DB::transaction(function () use ($primary, $dependent, $relation): PatientRelation {
            $owner = $primary->primaryRelation->primary ?? $primary;

            if ($owner->is($dependent)) {
                throw new DependentAlreadyLinked('A primary cannot be its own dependent.');
            }

            if (PatientRelation::query()->where('dependent_patient_id', $dependent->id)->exists()) {
                throw new DependentAlreadyLinked;
            }

            return PatientRelation::query()->create([
                'primary_patient_id' => $owner->id,
                'dependent_patient_id' => $dependent->id,
                'relation' => $relation,
            ]);
        });
    }
}
