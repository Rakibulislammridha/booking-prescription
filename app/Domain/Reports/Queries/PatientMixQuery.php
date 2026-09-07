<?php

declare(strict_types=1);

namespace App\Domain\Reports\Queries;

use App\Domain\Prescription\Enums\VisitStatus;
use App\Domain\Reports\Data\ReportFilters;
use App\Domain\Reports\Support\LocalTime;
use App\Domain\Reports\Support\Row;
use App\Support\Clock;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * New vs returning patients, and the follow-up compliance rate (BRIEF §5.L, fourth line).
 *
 * NEW vs RETURNING is counted on DISTINCT PATIENTS, not on visits:
 *  - the population is every patient with at least one non-cancelled visit inside the range;
 *  - a patient is NEW when his first-ever visit at this clinic falls inside the range, RETURNING when it falls
 *    before it. "First ever" is clinic-wide and ignores the doctor filter deliberately — a cardiologist's
 *    patient who has been coming to the clinic's paediatrician for years is not new to the clinic, and a report
 *    that said otherwise would double-count him every time a new doctor saw him.
 *  - a patient seen twice in the range, or by two doctors, counts ONCE at clinic level. In the per-doctor
 *    breakdown he appears under both doctors, so those rows deliberately sum to more than the clinic total;
 *    that is footnoted on the page rather than silently reconciled.
 *
 * FOLLOW-UP COMPLIANCE is a three-step funnel over visits that advised one (`visits.follow_up_on IS NOT NULL`):
 *  - ADVISED  the doctor wrote a follow-up date.
 *  - DUE      that date has already passed. The rate is computed on DUE, not on ADVISED: a follow-up advised
 *             for next month has not been missed yet, and including it makes every recent period look terrible.
 *  - BOOKED   an appointment exists with `follow_up_of_visit_id = visit.id` whose status is not `draft`.
 *             The draft row is the system's own auto-created placeholder (Booking's
 *             CreateDraftFollowUpAppointment listener), not a booking a human made, so it does not count.
 *  - KEPT     that appointment reached status `completed`, which Booking's SyncAppointmentWithSerial mirrors
 *             from the serial actually completing. Booked-but-not-kept is therefore visible as a separate
 *             number, which is the one a clinic can act on.
 *
 * INDEXES: `visits_started_at_idx` (this module's own migration) for the clinic-wide period scan, or
 * `visits_doctor_id_started_at_idx` when a doctor filter is present; `visits_patient_id_started_at_idx` for the
 * per-patient "first ever" minimum; `visits_follow_up_on_idx_p` for the funnel's driving set and
 * `appointments_follow_up_of_visit_id_idx` for its lateral lookup.
 */
final class PatientMixQuery
{
    /** @return array<string, mixed> */
    public function summary(ReportFilters $filters): array
    {
        return [
            'totals' => $this->newVsReturning($filters),
            'by_period' => $this->byPeriod($filters),
            'by_doctor' => $this->byDoctor($filters),
            'follow_up' => $this->followUpCompliance($filters),
            'granularity' => $filters->granularity(),
        ];
    }

    /** @return array<string, mixed> */
    public function newVsReturning(ReportFilters $filters): array
    {
        $row = $this->firstVisits($filters)
            ->selectRaw('count(*) as patients, count(*) filter (where first_ever >= ?) as new_patients, count(*) filter (where first_ever < ?) as returning_patients', [$filters->startUtc(), $filters->startUtc()])
            ->first();

        $patients = Row::int($row, 'patients');
        $new = Row::int($row, 'new_patients');

        return [
            'patients' => $patients,
            'new' => $new,
            'returning' => Row::int($row, 'returning_patients'),
            'new_rate' => $patients === 0 ? null : round($new / $patients * 100, 1),
        ];
    }

    /**
     * The trend answers a DIFFERENT question from the totals, and the page says so: how many distinct patients
     * did the clinic see on each day, and how many of those were new to it. A patient who came on Sunday and
     * again on Tuesday is counted on both days — bucketing them only on their first day in the range would
     * draw a chart that collapses to zero after day one, which is an artefact of the counting rule and not
     * something that happened in the clinic.
     *
     * The "new" flag is still clinic-wide and still evaluated once per patient (the joined subquery), so a
     * patient is new on every day of the range in which they appear only if their very first visit ever falls
     * inside it.
     *
     * @return array<int, array<string, mixed>>
     */
    public function byPeriod(ReportFilters $filters): array
    {
        $bucket = LocalTime::bucket('v.started_at', $filters->granularity());

        $query = DB::table('visits as v')
            ->joinSub($this->seenInRange($filters), 'seen', 'seen.patient_id', '=', 'v.patient_id')
            ->where('v.status', '!=', VisitStatus::Cancelled->value)
            ->where('v.started_at', '>=', $filters->startUtc())
            ->where('v.started_at', '<', $filters->endUtc());

        $this->applyVisitFilters($query, $filters);

        $rows = $query
            ->selectRaw("{$bucket} as k,
                count(distinct v.patient_id) as patients,
                count(distinct v.patient_id) filter (where seen.first_ever >= ?) as new_patients,
                count(distinct v.patient_id) filter (where seen.first_ever < ?) as returning_patients", [$filters->startUtc(), $filters->startUtc()])
            ->groupBy(DB::raw($bucket))
            ->orderBy('k')
            ->get();

        return $rows->map(function (mixed $row): array {
            $patients = Row::int($row, 'patients');
            $new = Row::int($row, 'new_patients');

            return [
                'period' => Row::string($row, 'k'),
                'patients' => $patients,
                'new' => $new,
                'returning' => Row::int($row, 'returning_patients'),
                'new_rate' => $patients === 0 ? null : round($new / $patients * 100, 1),
            ];
        })->all();
    }

    /**
     * Per doctor. A patient seen by two doctors is counted under each — the rows are a doctor's own book, not a
     * partition of the clinic, and the page says so.
     *
     * @return array<int, array<string, mixed>>
     */
    public function byDoctor(ReportFilters $filters): array
    {
        $rows = DB::query()
            ->fromSub($this->seenByDoctor($filters), 'sd')
            ->join('doctors as d', 'd.id', '=', 'sd.doctor_id')
            ->selectRaw('sd.doctor_id, d.name as doctor_name, d.public_id as doctor_public_id, count(*) as patients, count(*) filter (where sd.first_ever >= ?) as new_patients, count(*) filter (where sd.first_ever < ?) as returning_patients', [$filters->startUtc(), $filters->startUtc()])
            ->groupBy('sd.doctor_id', 'd.name', 'd.public_id')
            ->orderByDesc('patients')
            ->get();

        return $rows->map(function (mixed $row): array {
            $patients = Row::int($row, 'patients');
            $new = Row::int($row, 'new_patients');

            return [
                'doctor_id' => Row::int($row, 'doctor_id'),
                'doctor_name' => Row::nullableString($row, 'doctor_name'),
                'doctor_public_id' => Row::nullableString($row, 'doctor_public_id'),
                'patients' => $patients,
                'new' => $new,
                'returning' => Row::int($row, 'returning_patients'),
                'new_rate' => $patients === 0 ? null : round($new / $patients * 100, 1),
            ];
        })->all();
    }

    /** @return array<string, mixed> */
    public function followUpCompliance(ReportFilters $filters): array
    {
        $today = Clock::today()->toDateString();

        $query = DB::table('visits as v')
            ->joinSub(
                DB::table('appointments as a')
                    ->selectRaw("a.follow_up_of_visit_id, bool_or(true) as booked, bool_or(a.status = 'completed') as kept")
                    ->whereNotNull('a.follow_up_of_visit_id')
                    ->where('a.status', '!=', 'draft')
                    ->groupBy('a.follow_up_of_visit_id'),
                'fa',
                'fa.follow_up_of_visit_id',
                '=',
                'v.id',
                'left',
            )
            ->whereNotNull('v.follow_up_on')
            ->where('v.status', '!=', VisitStatus::Cancelled->value)
            ->where('v.started_at', '>=', $filters->startUtc())
            ->where('v.started_at', '<', $filters->endUtc());

        $this->applyVisitFilters($query, $filters);

        $row = $query->selectRaw(
            'count(*) as advised,
             count(*) filter (where v.follow_up_on < ?) as due,
             count(*) filter (where coalesce(fa.booked, false)) as booked,
             count(*) filter (where coalesce(fa.kept, false)) as kept,
             count(*) filter (where v.follow_up_on < ? and coalesce(fa.booked, false)) as booked_due,
             count(*) filter (where v.follow_up_on < ? and coalesce(fa.kept, false)) as kept_due',
            [$today, $today, $today],
        )->first();

        $due = Row::int($row, 'due');

        return [
            'advised' => Row::int($row, 'advised'),
            'due' => $due,
            'booked' => Row::int($row, 'booked'),
            'kept' => Row::int($row, 'kept'),
            'booked_due' => Row::int($row, 'booked_due'),
            'kept_due' => Row::int($row, 'kept_due'),
            'booking_rate' => $due === 0 ? null : round(Row::int($row, 'booked_due') / $due * 100, 1),
            'compliance_rate' => $due === 0 ? null : round(Row::int($row, 'kept_due') / $due * 100, 1),
            'as_of' => $today,
        ];
    }

    /**
     * Distinct patients seen in the range, each carrying the moment they were first seen in it and the moment
     * they were first EVER seen at the clinic. The correlated minimum runs once per patient in the range (not
     * once per visit) and lands straight on `visits_patient_id_started_at_idx`; `byPeriod()` joins the same
     * subquery rather than repeating it.
     */
    private function firstVisits(ReportFilters $filters): Builder
    {
        return DB::query()->fromSub($this->seenInRange($filters), 'seen');
    }

    private function seenInRange(ReportFilters $filters): Builder
    {
        $query = DB::table('visits as v')
            ->where('v.status', '!=', VisitStatus::Cancelled->value)
            ->where('v.started_at', '>=', $filters->startUtc())
            ->where('v.started_at', '<', $filters->endUtc())
            ->groupBy('v.patient_id')
            ->selectRaw('v.patient_id, min(v.started_at) as first_in_window, (select min(v2.started_at) from visits v2 where v2.patient_id = v.patient_id and v2.status <> ?) as first_ever', [VisitStatus::Cancelled->value]);

        $this->applyVisitFilters($query, $filters);

        return $query;
    }

    private function seenByDoctor(ReportFilters $filters): Builder
    {
        $query = DB::table('visits as v')
            ->where('v.status', '!=', VisitStatus::Cancelled->value)
            ->where('v.started_at', '>=', $filters->startUtc())
            ->where('v.started_at', '<', $filters->endUtc())
            ->groupBy('v.doctor_id', 'v.patient_id')
            ->selectRaw('v.doctor_id, v.patient_id, (select min(v2.started_at) from visits v2 where v2.patient_id = v.patient_id and v2.status <> ?) as first_ever', [VisitStatus::Cancelled->value]);

        $this->applyVisitFilters($query, $filters);

        return $query;
    }

    private function applyVisitFilters(Builder $query, ReportFilters $filters): void
    {
        if ($filters->branchId !== null) {
            $query->where('v.branch_id', $filters->branchId);
        }

        if ($filters->doctorId !== null) {
            $query->where('v.doctor_id', $filters->doctorId);
        }
    }
}
