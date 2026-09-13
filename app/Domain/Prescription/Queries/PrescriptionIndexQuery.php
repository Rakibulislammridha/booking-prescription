<?php

declare(strict_types=1);

namespace App\Domain\Prescription\Queries;

use App\Domain\Clinic\Services\DoctorScope;
use App\Domain\Patients\Contracts\PatientAccessResolver;
use App\Domain\Patients\Services\PatientSearch;
use App\Domain\Prescription\Data\PrescriptionIndexFilters;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\Patient;
use App\Models\Tenant\Prescription;
use App\Models\Tenant\User;
use App\Support\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The prescriptions index (`panel.prescriptions.index`): newest first by the moment that matters — issue time, or
 * creation time for a draft — fifty a page, with the patient / doctor / visit rows loaded in three more queries
 * however long the page is. Row-level access is the Patients module's own rule (PatientAccessResolver, BRIEF §5.N):
 * a doctor without `prescriptions.view.any` sees the prescriptions of the patients they have treated and nobody
 * else's; everyone the resolver leaves unconstrained (admin, desk, the wider permission) sees the clinic's.
 *
 * A DoctorScope-restricted caller (a compounder) is filtered TWICE, and both are needed. The patient constraint
 * answers "is this patient mine?", but a patient treated by two doctors is mine through one of them and the
 * colleague's sheets for that same patient would ride along; `prescriptions.doctor_id` answers "is this SHEET
 * mine?". Belt and braces on the list that PrescriptionPolicy::view guards one row at a time.
 */
final class PrescriptionIndexQuery
{
    public const PER_PAGE = 50;

    /** Never `snapshot`: fifty of them carry fifty inlined logos and QR codes, and the list renders none of it. */
    private const COLUMNS = [
        'id', 'public_id', 'visit_id', 'patient_id', 'doctor_id', 'branch_id', 'version', 'root_prescription_id',
        'supersedes_prescription_id', 'status', 'language', 'issued_at', 'verification_code', 'pdf_path', 'amend_reason',
        'voided_at', 'printed_count', 'created_at',
    ];

    private const MOMENT = 'coalesce(prescriptions.issued_at, prescriptions.created_at)';

    public function __construct(private readonly PatientAccessResolver $access, private readonly DoctorScope $scope) {}

    /** @return LengthAwarePaginator<int, Prescription> */
    public function paginate(User $user, PrescriptionIndexFilters $filters): LengthAwarePaginator
    {
        $query = Prescription::query()
            ->select(array_map(fn (string $column): string => "prescriptions.{$column}", self::COLUMNS))
            // Both soft-delete; a prescription stays on record after its patient was merged away or its doctor
            // retired, and the row still names them. So the relations the FKs guarantee are never null here.
            ->with(['patient' => self::evenIfTrashed(...), 'doctor' => self::evenIfTrashed(...), 'visit'])
            ->withCount('items')
            ->orderByRaw(self::MOMENT.' desc')
            ->orderByDesc('prescriptions.id');

        $this->constrainToAccessiblePatients($user, $query);
        $doctorIds = $this->scope->doctorIds($user);
        $query->when($doctorIds !== null, fn ($q) => $q->whereIn('prescriptions.doctor_id', $doctorIds ?? []));
        $this->applyFilters($query, $filters);

        return $query->paginate(self::PER_PAGE, ['*'], 'page', $filters->page)->withQueryString();
    }

    /**
     * The doctor filter's choices. Every active doctor for an unrestricted caller, deliberately: a restricted
     * doctor's list already shows a colleague's name on a shared patient's row, so the dropdown hides nothing the
     * rows do not. That argument does NOT carry to a DoctorScope-restricted caller, whose rows name their own
     * doctors only — so they get their own doctors, and a dropdown that cannot ask a question the list would refuse.
     *
     * @param  list<int>|null  $doctorIds  DoctorScope: null = unrestricted, a list = only these doctors, [] = none
     * @return array<int, array{public_id: string, name: string, name_bn: string|null}>
     */
    public static function doctorOptions(?array $doctorIds = null): array
    {
        return Doctor::query()->active()->when($doctorIds !== null, fn ($q) => $q->whereIn('id', $doctorIds ?? []))
            ->orderBy('sort_order')->orderBy('name')->get(['public_id', 'name', 'name_bn'])
            ->map(fn (Doctor $doctor): array => ['public_id' => $doctor->public_id, 'name' => $doctor->name, 'name_bn' => $doctor->name_bn])
            ->all();
    }

    /**
     * The resolver constrains a *patient* query; the index is a prescription query. Ask it to constrain a fresh
     * patient query and, only when it actually did, keep the prescriptions whose patient survives that constraint.
     * An unconstrained user pays no subquery at all.
     *
     * @param  Builder<Prescription>  $query
     */
    private function constrainToAccessiblePatients(User $user, Builder $query): void
    {
        $patients = Patient::query();
        $this->access->constrain($user, $patients);

        if ($patients->getQuery()->wheres !== []) {
            $query->whereIn('prescriptions.patient_id', $patients->select('patients.id'));
        }
    }

    /** @param  BelongsTo<Patient, Prescription>|BelongsTo<Doctor, Prescription>  $relation */
    private static function evenIfTrashed(BelongsTo $relation): void
    {
        $relation->withTrashed();
    }

    /** @param  Builder<Prescription>  $query */
    private function applyFilters(Builder $query, PrescriptionIndexFilters $filters): void
    {
        if ($filters->q !== '') {
            $matches = Patient::query()->select('patients.id');
            PatientSearch::applyTerm($matches, $filters->q);
            $query->whereIn('prescriptions.patient_id', $matches);
        }

        if ($filters->doctor !== null) {
            $query->where('prescriptions.doctor_id', (int) (Doctor::query()->where('public_id', $filters->doctor)->value('id') ?? 0));
        }

        if ($filters->status !== null) {
            $query->where('prescriptions.status', $filters->status->value);
        }

        if ($filters->from !== null) {
            $query->whereRaw(self::MOMENT.' >= ?', [self::dayStart($filters->from)->toIso8601String()]);
        }

        if ($filters->to !== null) {
            $query->whereRaw(self::MOMENT.' < ?', [self::dayStart($filters->to)->addDay()->toIso8601String()]);
        }
    }

    /** Midnight of a Dhaka calendar day, in UTC — the day the desk means, not the server's. */
    private static function dayStart(string $date): CarbonImmutable
    {
        return CarbonImmutable::parse($date, Clock::timezone())->startOfDay()->utc();
    }
}
