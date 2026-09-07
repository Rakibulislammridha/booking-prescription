<?php

declare(strict_types=1);

namespace App\Domain\Patients\Actions;

use App\Domain\Shared\Actor;
use App\Models\Tenant\PatientAllergy;

/** Clinical rows are never deleted (CONVENTIONS §3.2): "remove" deactivates. */
final class RemoveAllergy
{
    public function handle(PatientAllergy $allergy, Actor $actor): PatientAllergy
    {
        $allergy->fill(['is_active' => false])->save();

        return $allergy;
    }
}
