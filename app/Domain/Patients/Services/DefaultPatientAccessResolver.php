<?php

declare(strict_types=1);

namespace App\Domain\Patients\Services;

use App\Domain\Clinic\Enums\Permission;
use App\Domain\Clinic\Enums\Role;
use App\Domain\Patients\Contracts\PatientAccessResolver;
use App\Models\Tenant\Patient;
use App\Models\Tenant\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Row-level access (BRIEF §5.N) with the data that exists today:
 *  - hospital_admin / receptionist / accountant (anyone holding patients.view who is not a doctor): every patient;
 *  - a doctor with `prescriptions.view.any`: every patient;
 *  - a doctor otherwise: every patient until the Prescription module's `visits` table exists; from then on a
 *    patient the doctor has treated (a visits row with this doctor) or a patient nobody has treated yet
 *    (new registration, booked but not yet seen) — so the desk → doctor hand-off never 403s.
 * The Prescription module may rebind PatientAccessResolver to add appointment/serial awareness.
 */
final class DefaultPatientAccessResolver implements PatientAccessResolver
{
    private ?bool $visitsExist = null;

    public function canAccess(User $user, Patient $patient): bool
    {
        $doctorId = $this->restrictedDoctorId($user);

        if ($doctorId === null) {
            return true;
        }

        if ($doctorId === 0) {
            return false;
        }

        $visits = $this->visits()->where('patient_id', $patient->id);

        return ! (clone $visits)->exists() || (clone $visits)->where('doctor_id', $doctorId)->exists();
    }

    /** @param  Builder<Patient>  $query */
    public function constrain(User $user, Builder $query): void
    {
        $doctorId = $this->restrictedDoctorId($user);

        if ($doctorId === null) {
            return;
        }

        if ($doctorId === 0) {
            $query->whereRaw('1 = 0');

            return;
        }

        $query->where(function (Builder $q) use ($doctorId): void {
            $q->whereNotExists(fn (QueryBuilder $v) => $v->selectRaw('1')->from('visits')->whereColumn('visits.patient_id', 'patients.id'))
                ->orWhereExists(fn (QueryBuilder $v) => $v->selectRaw('1')->from('visits')->whereColumn('visits.patient_id', 'patients.id')->where('visits.doctor_id', $doctorId));
        });
    }

    /** null = unrestricted; 0 = doctor role without a doctors row (sees nothing); else the doctor id to match. */
    private function restrictedDoctorId(User $user): ?int
    {
        if (! $user->hasRole(Role::Doctor->value) || $user->can(Permission::PrescriptionsViewAny->value)) {
            return null;
        }

        if (! $this->visitsTableExists()) {
            return null;
        }

        $doctorId = $user->doctor()->value('id');

        return $doctorId === null ? 0 : (int) $doctorId;
    }

    private function visitsTableExists(): bool
    {
        return $this->visitsExist ??= Schema::connection('pgsql')->hasTable('visits');
    }

    private function visits(): QueryBuilder
    {
        return DB::connection('pgsql')->table('visits');
    }
}
