<?php

declare(strict_types=1);

namespace App\Domain\Reports\Queries;

use App\Domain\Reports\Data\ReportFilters;
use App\Domain\Reports\Support\LocalTime;
use App\Domain\Reports\Support\Row;
use App\Domain\Serials\Services\ConsultAverage;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Average wait time and average consultation time (BRIEF §5.L, second line).
 *
 * DEFINITIONS (footnoted on the page):
 *  - WAIT = `called_at − checked_in_at`. It starts when the patient tells the desk he is here, not when he
 *    booked: a serial booked a week ago did not wait a week. Only serials with both stamps and a non-negative
 *    difference are sampled, so a never-arrived no-show contributes nothing rather than a zero that would drag
 *    the mean down.
 *  - CONSULTATION = `completed_at − called_at`, exactly the sample `App\Domain\Serials\Services\ConsultAverage`
 *    feeds into the live ETA, INCLUDING its 30 s – 30 min acceptance clamp. The clamp is not cosmetic: a doctor
 *    who forgets to press "complete" until the evening produces a four-hour "consultation" that would otherwise
 *    move the clinic's average by minutes. The number of clamped-out samples is reported next to the average so
 *    the exclusion is visible rather than hidden.
 *  - Because a mean is easy to skew, p50 and p90 are reported beside it. The queue is staffed for p90, not for
 *    the mean.
 *
 * INDEXES: driven off `session_instances.session_date` (already clinic-local) —
 * `session_instances_session_date_branch_id_idx` / `session_instances_doctor_id_session_date_idx`, then
 * `serials_session_instance_id_status_idx` for the nested loop. One aggregate per grouping (totals, by doctor,
 * by period); the sample predicates are FILTER clauses, so all six statistics come from a single pass.
 */
final class WaitTimeQuery
{
    public const CONSULT_MIN_SECONDS = ConsultAverage::CLAMP[0];

    public const CONSULT_MAX_SECONDS = ConsultAverage::CLAMP[1];

    /** @return array<string, mixed> */
    public function summary(ReportFilters $filters): array
    {
        return [
            'totals' => $this->totals($filters),
            'by_doctor' => $this->byDoctor($filters),
            'by_period' => $this->byPeriod($filters),
            'overrun' => $this->overrun($filters),
            'clamp' => ['min_seconds' => self::CONSULT_MIN_SECONDS, 'max_seconds' => self::CONSULT_MAX_SECONDS],
            'granularity' => $filters->granularity(),
        ];
    }

    /** @return array<string, mixed> */
    public function totals(ReportFilters $filters): array
    {
        return self::shape($this->base($filters)->selectRaw(self::stats())->first());
    }

    /** @return array<int, array<string, mixed>> */
    public function byDoctor(ReportFilters $filters): array
    {
        $rows = $this->base($filters)
            ->join('doctors as d', 'd.id', '=', 'si.doctor_id')
            ->selectRaw('si.doctor_id, d.name as doctor_name, d.public_id as doctor_public_id, '.self::stats())
            ->groupBy('si.doctor_id', 'd.name', 'd.public_id')
            ->get();

        $out = $rows->map(fn (mixed $row): array => [
            'doctor_id' => Row::int($row, 'doctor_id'),
            'doctor_name' => Row::nullableString($row, 'doctor_name'),
            'doctor_public_id' => Row::nullableString($row, 'doctor_public_id'),
        ] + self::shape($row))->all();

        usort($out, static fn (array $a, array $b): int => ($b['wait_samples'] <=> $a['wait_samples']));

        return $out;
    }

    /** @return array<int, array<string, mixed>> */
    public function byPeriod(ReportFilters $filters): array
    {
        $bucket = LocalTime::dateBucket('si.session_date', $filters->granularity());

        $rows = $this->base($filters)
            ->selectRaw("{$bucket} as k, ".self::stats())
            ->groupBy(DB::raw($bucket))
            ->orderBy('k')
            ->get();

        return $rows->map(fn (mixed $row): array => ['period' => Row::string($row, 'k')] + self::shape($row))->all();
    }

    /**
     * Session overrun against the PLANNED end time (BRIEF §5.L "session overrun analysis").
     *
     * Only CLOSED sessions count: a session still running has no end to compare, and a cancelled one never had
     * a chance to overrun. `overran` uses a 15-minute grace — every OPD ends a few minutes late and calling that
     * an overrun makes the metric useless. Late start is reported separately because the two have different
     * fixes: a late start is the doctor's arrival, an overrun is the capacity.
     *
     * INDEX: `session_instances_session_date_branch_id_idx` (or the doctor-leading one) — a single grouped pass
     * over the sessions themselves, no join to serials at all.
     *
     * @return array<string, mixed>
     */
    public function overrun(ReportFilters $filters): array
    {
        $stats = <<<'SQL'
            count(*) as sessions,
            avg(extract(epoch from (si.actual_end_at - si.planned_end_at))) as overrun_avg,
            max(extract(epoch from (si.actual_end_at - si.planned_end_at))) as overrun_max,
            count(*) filter (where si.actual_end_at > si.planned_end_at + interval '15 minutes') as overran,
            avg(extract(epoch from (si.actual_start_at - si.planned_start_at))) filter (where si.actual_start_at is not null) as late_start_avg,
            avg(si.pause_seconds) as pause_avg
        SQL;

        $query = DB::table('session_instances as si')
            ->whereBetween('si.session_date', [$filters->fromDate(), $filters->toDate()])
            ->where('si.status', 'closed')
            ->whereNotNull('si.actual_end_at');

        if ($filters->branchId !== null) {
            $query->where('si.branch_id', $filters->branchId);
        }

        if ($filters->doctorId !== null) {
            $query->where('si.doctor_id', $filters->doctorId);
        }

        $byDoctor = (clone $query)
            ->join('doctors as d', 'd.id', '=', 'si.doctor_id')
            ->selectRaw('si.doctor_id, d.name as doctor_name, '.$stats)
            ->groupBy('si.doctor_id', 'd.name')
            ->get()
            ->map(fn (mixed $row): array => [
                'doctor_id' => Row::int($row, 'doctor_id'),
                'doctor_name' => Row::nullableString($row, 'doctor_name'),
            ] + self::overrunShape($row))
            ->all();

        usort($byDoctor, static fn (array $a, array $b): int => ($b['overrun_avg_minutes'] ?? -1e9) <=> ($a['overrun_avg_minutes'] ?? -1e9));

        return ['totals' => self::overrunShape($query->selectRaw($stats)->first()), 'by_doctor' => $byDoctor];
    }

    private function base(ReportFilters $filters): Builder
    {
        $query = DB::table('serials as s')
            ->join('session_instances as si', 'si.id', '=', 's.session_instance_id')
            ->whereBetween('si.session_date', [$filters->fromDate(), $filters->toDate()]);

        if ($filters->branchId !== null) {
            $query->where('si.branch_id', $filters->branchId);
        }

        if ($filters->doctorId !== null) {
            $query->where('si.doctor_id', $filters->doctorId);
        }

        return $query;
    }

    /**
     * One pass, six statistics. `wait` and `consult` have different sample predicates, which is exactly what
     * FILTER is for; `percentile_cont … WITHIN GROUP … FILTER` is valid Postgres and skips the rest.
     *
     * The consultation clamp is INTERPOLATED from `ConsultAverage::CLAMP` rather than written into the SQL as
     * literals. The two numbers must be the same numbers the live queue accepts, and a constant that the SQL
     * merely happens to agree with today is a constant that silently disagrees the day someone widens the
     * clamp — leaving the ETA and the report quoting different averages for the same doctor.
     */
    private static function stats(): string
    {
        $lo = self::CONSULT_MIN_SECONDS;
        $hi = self::CONSULT_MAX_SECONDS;

        $waited = 's.checked_in_at is not null and s.called_at is not null and s.called_at >= s.checked_in_at';
        $wait = 'extract(epoch from (s.called_at - s.checked_in_at))';
        $consulted = "s.status = 'completed' and s.called_at is not null and s.completed_at is not null";
        $consult = 'extract(epoch from (s.completed_at - s.called_at))';
        $inClamp = "{$consulted} and {$consult} between {$lo} and {$hi}";

        return <<<SQL
            count(*) filter (where {$waited}) as wait_n,
            avg({$wait}) filter (where {$waited}) as wait_avg,
            percentile_cont(0.5) within group (order by {$wait}) filter (where {$waited}) as wait_p50,
            percentile_cont(0.9) within group (order by {$wait}) filter (where {$waited}) as wait_p90,
            count(*) filter (where {$inClamp}) as consult_n,
            avg({$consult}) filter (where {$inClamp}) as consult_avg,
            percentile_cont(0.5) within group (order by {$consult}) filter (where {$inClamp}) as consult_p50,
            percentile_cont(0.9) within group (order by {$consult}) filter (where {$inClamp}) as consult_p90,
            count(*) filter (where {$consulted} and {$consult} not between {$lo} and {$hi}) as consult_clamped
        SQL;
    }

    /** @return array<string, mixed> */
    private static function shape(mixed $row): array
    {
        return [
            'wait_samples' => Row::int($row, 'wait_n'),
            'wait_avg_minutes' => Row::minutes($row, 'wait_avg'),
            'wait_p50_minutes' => Row::minutes($row, 'wait_p50'),
            'wait_p90_minutes' => Row::minutes($row, 'wait_p90'),
            'consult_samples' => Row::int($row, 'consult_n'),
            'consult_avg_minutes' => Row::minutes($row, 'consult_avg'),
            'consult_p50_minutes' => Row::minutes($row, 'consult_p50'),
            'consult_p90_minutes' => Row::minutes($row, 'consult_p90'),
            'consult_excluded' => Row::int($row, 'consult_clamped'),
        ];
    }

    /** @return array<string, mixed> */
    private static function overrunShape(mixed $row): array
    {
        $sessions = Row::int($row, 'sessions');

        return [
            'sessions' => $sessions,
            'overrun_avg_minutes' => Row::minutes($row, 'overrun_avg'),
            'overrun_max_minutes' => Row::minutes($row, 'overrun_max'),
            'overran' => Row::int($row, 'overran'),
            'overran_rate' => $sessions === 0 ? null : round(Row::int($row, 'overran') / $sessions * 100, 1),
            'late_start_avg_minutes' => Row::minutes($row, 'late_start_avg'),
            'pause_avg_minutes' => Row::minutes($row, 'pause_avg'),
        ];
    }
}
