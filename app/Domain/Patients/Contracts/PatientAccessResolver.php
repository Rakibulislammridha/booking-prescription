<?php

declare(strict_types=1);

namespace App\Domain\Patients\Contracts;

use App\Models\Tenant\Patient;
use App\Models\Tenant\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Row-level "may this staff user see this patient?" (BRIEF §5.N: a doctor sees only his own patients unless
 * explicitly permitted). PatientPolicy delegates here after the permission check. The default resolver
 * (DefaultPatientAccessResolver) grants everything until visit data exists; the Prescription module may rebind.
 */
interface PatientAccessResolver
{
    public function canAccess(User $user, Patient $patient): bool;

    /**
     * Restrict a patient list query to the rows $user may see.
     *
     * @param  Builder<Patient>  $query
     */
    public function constrain(User $user, Builder $query): void;
}
