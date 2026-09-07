<?php

declare(strict_types=1);

namespace App\Domain\Reports\Queries;

use App\Domain\Reports\Data\ReportFilters;
use App\Domain\Reports\Data\ReportScope;
use App\Domain\Reports\Support\Row;
use App\Domain\Scheduling\Enums\SessionStatus;
use App\Support\Clock;
use Illuminate\Support\Facades\DB;

/**
 * The day at a glance — what a clinic owner opens on a Sunday morning (BRIEF §5.L, and the reason this module
 * exists at all).
 *
 * Everything is TODAY in the clinic's timezone, and every figure is the same figure the matching detailed
 * report would give for a one-day range: the tiles delegate to `AppointmentVolumeQuery`, `WaitTimeQuery` and
 * `RevenueQuery` rather than re-deriving anything. The money tile is only computed for a scope that may see
 * money, so a doctor's dashboard does not merely hide the clinic's revenue — it never queries it.
 *
 * `live` is the one thing here that is not a report: how many people are in the building right now. It is a
 * single grouped aggregate over today's sessions, served by `session_instances_session_date_branch_id_idx` and
 * `serials_session_instance_id_status_idx`, and it is what makes the page worth leaving open.
 */
final class DailySnapshotQuery
{
    public function __construct(
        private readonly AppointmentVolumeQuery $volume,
        private readonly WaitTimeQuery $waits,
        private readonly RevenueQuery $revenue,
        private readonly PatientMixQuery $patients,
    ) {}

    /** @return array<string, mixed> */
    public function today(ReportFilters $filters, ReportScope $scope): array
    {
        $today = $filters->today();

        return [
            'date' => $today->fromDate(),
            'timezone' => Clock::timezone(),
            'appointments' => $this->volume->summary($today)['totals'],
            'waits' => $this->waits->totals($today),
            'patients' => $this->patients->newVsReturning($today),
            'money' => $scope->financial ? $this->revenue->totals($today) : null,
            'live' => $this->live($today),
            'sessions' => $this->sessions($today),
        ];
    }

    /**
     * Right now: who is waiting, who is with the doctor, how many sessions are open.
     *
     * @return array<string, int>
     */
    public function live(ReportFilters $filters): array
    {
        $query = DB::table('serials as s')
            ->join('session_instances as si', 'si.id', '=', 's.session_instance_id')
            ->where('si.session_date', $filters->fromDate());

        if ($filters->branchId !== null) {
            $query->where('si.branch_id', $filters->branchId);
        }

        if ($filters->doctorId !== null) {
            $query->where('si.doctor_id', $filters->doctorId);
        }

        $row = $query->selectRaw(
            "count(*) filter (where s.status = 'checked_in') as waiting,
             count(*) filter (where s.status = 'in_consultation') as in_consultation,
             count(*) filter (where s.status = 'booked') as not_arrived",
        )->first();

        return [
            'waiting' => Row::int($row, 'waiting'),
            'in_consultation' => Row::int($row, 'in_consultation'),
            'not_arrived' => Row::int($row, 'not_arrived'),
        ];
    }

    /**
     * Today's sessions with their counts.
     *
     * The counts are AGGREGATED FROM `serials`, not read from the `session_instances.*_count` columns, even
     * though `CountsRecalculator` maintains those on every transition. Two panels on one screen must not be
     * able to disagree: the tiles above are a `count(*) FILTER` over serials, and a denormalised counter that
     * has drifted for any reason — a bulk repair, a restore, a seeded demo — would put a different number two
     * inches below the same figure. It is one grouped join over ONE day's serials, served by
     * `serials_session_instance_id_status_idx`; correctness is worth more than the row read it saves.
     *
     * @return array<int, array<string, mixed>>
     */
    public function sessions(ReportFilters $filters): array
    {
        $counts = DB::table('serials as s')
            ->selectRaw("s.session_instance_id,
                count(*) as total,
                count(*) filter (where s.status = 'booked') as booked,
                count(*) filter (where s.status = 'checked_in') as checked_in,
                count(*) filter (where s.status = 'in_consultation') as in_consultation,
                count(*) filter (where s.status = 'completed') as completed,
                count(*) filter (where s.status = 'no_show') as no_show")
            ->groupBy('s.session_instance_id');

        $query = DB::table('session_instances as si')
            ->join('doctors as d', 'd.id', '=', 'si.doctor_id')
            ->join('branches as b', 'b.id', '=', 'si.branch_id')
            ->leftJoinSub($counts, 'c', 'c.session_instance_id', '=', 'si.id')
            ->where('si.session_date', $filters->fromDate())
            ->where('si.status', '!=', SessionStatus::Cancelled->value);

        if ($filters->branchId !== null) {
            $query->where('si.branch_id', $filters->branchId);
        }

        if ($filters->doctorId !== null) {
            $query->where('si.doctor_id', $filters->doctorId);
        }

        return $query
            ->select([
                'si.public_id', 'si.session_code', 'si.status', 'si.planned_start_at', 'si.planned_end_at',
                'si.max_serials', 'si.avg_consult_seconds', 'si.delay_minutes',
                'd.name as doctor_name', 'b.name as branch_name',
                'c.total', 'c.booked', 'c.checked_in', 'c.in_consultation', 'c.completed', 'c.no_show',
            ])
            ->orderBy('si.planned_start_at')
            ->orderBy('d.name')
            ->get()
            ->map(fn (mixed $row): array => [
                'public_id' => Row::string($row, 'public_id'),
                'session_code' => Row::string($row, 'session_code'),
                'status' => Row::string($row, 'status'),
                'doctor_name' => Row::nullableString($row, 'doctor_name'),
                'branch_name' => Row::nullableString($row, 'branch_name'),
                'planned_start_at' => Row::nullableString($row, 'planned_start_at'),
                'planned_end_at' => Row::nullableString($row, 'planned_end_at'),
                'issued' => Row::int($row, 'total'),
                'booked' => Row::int($row, 'booked'),
                'checked_in' => Row::int($row, 'checked_in'),
                'in_consultation' => Row::int($row, 'in_consultation'),
                'completed' => Row::int($row, 'completed'),
                'no_show' => Row::int($row, 'no_show'),
                'capacity' => Row::int($row, 'max_serials'),
                'avg_consult_seconds' => Row::int($row, 'avg_consult_seconds'),
                'delay_minutes' => Row::int($row, 'delay_minutes'),
            ])
            ->all();
    }
}
