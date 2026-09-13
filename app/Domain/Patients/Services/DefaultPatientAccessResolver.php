<?php

declare(strict_types=1);

namespace App\Domain\Patients\Services;

use App\Domain\Clinic\Enums\Permission;
use App\Domain\Clinic\Enums\Role;
use App\Domain\Clinic\Services\DoctorScope;
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
 *    (new registration, booked but not yet seen) — so the desk → doctor hand-off never 403s;
 *  - a compounder: only patients treated by the doctors they are assigned to (DoctorScope), and NOTHING when they
 *    are assigned to nobody.
 * The Prescription module may rebind PatientAccessResolver to add appointment/serial awareness.
 *
 * The compounder differs from the doctor in two deliberate ways. It is restricted even before `visits` exists — a
 * doctor's early-release grant is "you have no history yet, so you see everything", which for a compounder would
 * be the exact opposite of what they were hired into. And the "nobody has treated them yet" leg is NOT extended to
 * them: an untreated patient belongs to no doctor, so it belongs to no compounder either. Their own work creates
 * the link — vitals are recorded against a visit, which carries the doctor — and their arrival-desk actions reach a
 * patient through the serial, not through this list.
 *
 * A compounder holds no `patients.view` today, so PatientPolicy already refuses every patient screen; this class is
 * the layer that still holds if that permission is ever granted by mistake.
 */
final class DefaultPatientAccessResolver implements PatientAccessResolver
{
    private ?bool $visitsExist = null;

    public function __construct(private readonly DoctorScope $scope) {}

    public function canAccess(User $user, Patient $patient): bool
    {
        [$doctorIds, $untreatedToo] = $this->restriction($user);

        if ($doctorIds === null) {
            return true;
        }

        if ($doctorIds === [] || ! $this->visitsTableExists()) {
            return false;
        }

        $visits = $this->visits()->where('patient_id', $patient->id);

        if ($untreatedToo && ! (clone $visits)->exists()) {
            return true;
        }

        return (clone $visits)->whereIn('doctor_id', $doctorIds)->exists();
    }

    /** @param  Builder<Patient>  $query */
    public function constrain(User $user, Builder $query): void
    {
        [$doctorIds, $untreatedToo] = $this->restriction($user);

        if ($doctorIds === null) {
            return;
        }

        if ($doctorIds === [] || ! $this->visitsTableExists()) {
            $query->whereRaw('1 = 0');

            return;
        }

        $treated = fn (QueryBuilder $v) => $v->selectRaw('1')->from('visits')
            ->whereColumn('visits.patient_id', 'patients.id')->whereIn('visits.doctor_id', $doctorIds);

        $query->where(function (Builder $q) use ($treated, $untreatedToo): void {
            if (! $untreatedToo) {
                $q->whereExists($treated);

                return;
            }

            $q->whereNotExists(fn (QueryBuilder $v) => $v->selectRaw('1')->from('visits')->whereColumn('visits.patient_id', 'patients.id'))
                ->orWhereExists($treated);
        });
    }

    /**
     * The one place the rule lives.
     *
     * @return array{0: list<int>|null, 1: bool} doctor ids the user is restricted to (null = unrestricted, [] =
     *                                           nothing), and whether a patient nobody has treated yet is also theirs
     */
    private function restriction(User $user): array
    {
        // Asked every time, never memoised: un-assigning a compounder has to bite on the next request (DoctorScope).
        $assigned = $this->scope->doctorIds($user);

        if ($assigned !== null) {
            return [$assigned, false];
        }

        if (! $user->hasRole(Role::Doctor->value) || $user->can(Permission::PrescriptionsViewAny->value)) {
            return [null, true];
        }

        if (! $this->visitsTableExists()) {
            return [null, true];
        }

        $doctorId = $user->doctor()->value('id');

        return [$doctorId === null ? [] : [(int) $doctorId], true];
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
