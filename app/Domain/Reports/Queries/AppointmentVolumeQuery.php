<?php

declare(strict_types=1);

namespace App\Domain\Reports\Queries;

use App\Domain\Reports\Data\ReportFilters;
use App\Domain\Reports\Support\LocalTime;
use App\Domain\Reports\Support\Row;
use App\Domain\Serials\Enums\SerialStatus;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Appointments booked / completed / no-show by doctor, day and source (BRIEF §5.L, first line).
 *
 * DEFINITIONS (shown as footnotes on the page — a number nobody can define is a number nobody should trust):
 *  - `booked`     every serial issued for a session in the range, whatever became of it. This is demand.
 *  - `completed`  serials whose FINAL status is `completed`.
 *  - `no_show`    serials whose FINAL status is `no_show`, auto (SERIAL_ENGINE §8) or manual. A serial that was
 *                 no-showed and then reinstated is NOT counted: the status column is read now, not replayed.
 *  - `no-show rate` = no_show ÷ (booked − cancelled − postponed). Cancelled and postponed serials never became
 *                 an attendance opportunity, so leaving them in the denominator flatters the rate.
 *  - `source`     the serial's own `source` column: online/kiosk are patient self-service, counter/walkin/
 *                 offline are the desk, followup is the doctor's own rebooking. "Online vs counter" in the
 *                 brief is the `channel_group` folding of exactly that.
 *  - A TRANSFERRED serial appears twice: the old one is `cancelled` (reason `transferred`) under the original
 *    doctor and the new one is live under the receiving doctor. That is the truth of what happened at the desk,
 *    and the cancelled row is excluded from the rate denominator anyway.
 *
 * INDEXES. Everything is driven off `session_instances.session_date`, which is already the CLINIC-LOCAL day, so
 * no timezone conversion touches the WHERE clause and the index can be used:
 *  - `session_instances_session_date_branch_id_idx` for the clinic-wide range scan (and the branch filter);
 *  - `session_instances_doctor_id_session_date_idx` when a doctor filter is present (a scoped doctor always);
 *  - `serials_session_instance_id_status_idx` for the nested loop into the serials of each session.
 * Two aggregates total: one bucketed by period, one grouped by (doctor, source) and folded in PHP.
 */
final class AppointmentVolumeQuery
{
    /** @return array<string, mixed> */
    public function summary(ReportFilters $filters): array
    {
        $grid = $this->byDoctorAndSource($filters);
        $totals = $this->fold($grid, static fn (): string => 'all')[0] ?? self::emptyRow('all');

        return [
            'totals' => $totals,
            'by_period' => $this->byPeriod($filters),
            'by_doctor' => $this->fold($grid, static fn (array $r): string => (string) $r['doctor_id'], ['doctor_id', 'doctor_name', 'doctor_public_id']),
            'by_source' => $this->fold($grid, static fn (array $r): string => (string) $r['source'], ['source', 'channel_group']),
            'by_channel_group' => $this->fold($grid, static fn (array $r): string => (string) $r['channel_group'], ['channel_group']),
            'granularity' => $filters->granularity(),
        ];
    }

    /**
     * One row per period bucket (day / week / month, chosen by range length) — the chart's series.
     *
     * @return array<int, array<string, mixed>>
     */
    public function byPeriod(ReportFilters $filters): array
    {
        $bucket = LocalTime::dateBucket('si.session_date', $filters->granularity());

        $rows = $this->base($filters)
            ->selectRaw("{$bucket} as k, ".self::COUNTS)
            ->groupBy(DB::raw($bucket))
            ->orderBy('k')
            ->get();

        return $rows->map(fn (mixed $row): array => ['period' => Row::string($row, 'k')] + self::counts($row))->all();
    }

    /**
     * The (doctor × source) grid every summary block is folded from — one query, at most doctors × 6 rows.
     *
     * @return array<int, array<string, mixed>>
     */
    public function byDoctorAndSource(ReportFilters $filters): array
    {
        $rows = $this->base($filters)
            ->join('doctors as d', 'd.id', '=', 'si.doctor_id')
            ->selectRaw('si.doctor_id, d.name as doctor_name, d.public_id as doctor_public_id, s.source, '.self::COUNTS)
            ->groupBy('si.doctor_id', 'd.name', 'd.public_id', 's.source')
            ->get();

        return $rows->map(fn (mixed $row): array => [
            'doctor_id' => Row::int($row, 'doctor_id'),
            'doctor_name' => Row::nullableString($row, 'doctor_name'),
            'doctor_public_id' => Row::nullableString($row, 'doctor_public_id'),
            'source' => Row::string($row, 'source'),
            'channel_group' => self::channelGroup(Row::string($row, 'source')),
        ] + self::counts($row))->all();
    }

    /** BRIEF §5.L "online versus counter": the desk's own bucket, the patient's own bucket, and the doctor's. */
    public static function channelGroup(string $source): string
    {
        return match ($source) {
            'online', 'kiosk' => 'online',
            'followup' => 'followup',
            default => 'counter',
        };
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

    private const COUNTS = <<<'SQL'
        count(*) as booked,
        count(*) filter (where s.status = 'completed') as completed,
        count(*) filter (where s.status = 'no_show') as no_show,
        count(*) filter (where s.status = 'cancelled') as cancelled,
        count(*) filter (where s.status = 'postponed') as postponed,
        count(*) filter (where s.status in ('booked', 'checked_in', 'in_consultation')) as open_serials
    SQL;

    /** @return array<string, mixed> */
    private static function counts(mixed $row): array
    {
        $booked = Row::int($row, 'booked');
        $cancelled = Row::int($row, 'cancelled');
        $postponed = Row::int($row, 'postponed');

        return self::withRates([
            'booked' => $booked,
            'completed' => Row::int($row, 'completed'),
            'no_show' => Row::int($row, 'no_show'),
            'cancelled' => $cancelled,
            'postponed' => $postponed,
            'open' => Row::int($row, 'open_serials'),
            'expected' => $booked - $cancelled - $postponed,
        ]);
    }

    /**
     * @param  array<string, int>  $counts
     * @return array<string, mixed>
     */
    private static function withRates(array $counts): array
    {
        $expected = max(0, $counts['expected']);

        return $counts + [
            'no_show_rate' => $expected === 0 ? null : round($counts['no_show'] / $expected * 100, 1),
            'completion_rate' => $expected === 0 ? null : round($counts['completed'] / $expected * 100, 1),
        ];
    }

    /**
     * Sum the grid along one axis. Folding in PHP beats a second round trip: the grid is bounded by
     * doctors × 6 sources, and the alternative is three more GROUP BY passes over the same rows.
     *
     * @param  array<int, array<string, mixed>>  $grid
     * @param  callable(array<string, mixed>): string  $key
     * @param  array<int, string>  $carry  columns copied from the first row of each group
     * @return array<int, array<string, mixed>>
     */
    private function fold(array $grid, callable $key, array $carry = []): array
    {
        /** @var array<string, array<string, mixed>> $out */
        $out = [];

        foreach ($grid as $row) {
            $k = $key($row);

            if (! isset($out[$k])) {
                $out[$k] = self::emptyRow($k);

                foreach ($carry as $column) {
                    $out[$k][$column] = $row[$column] ?? null;
                }
            }

            foreach (['booked', 'completed', 'no_show', 'cancelled', 'postponed', 'open', 'expected'] as $metric) {
                $out[$k][$metric] += (int) $row[$metric];
            }
        }

        $rows = array_map(static function (array $row): array {
            /** @var array<string, int> $counts */
            $counts = array_intersect_key($row, array_flip(['booked', 'completed', 'no_show', 'cancelled', 'postponed', 'open', 'expected']));

            return array_merge($row, self::withRates($counts));
        }, array_values($out));

        usort($rows, static fn (array $a, array $b): int => $b['booked'] <=> $a['booked']);

        return $rows;
    }

    /** @return array<string, mixed> */
    private static function emptyRow(string $key): array
    {
        return [
            'key' => $key,
            'booked' => 0, 'completed' => 0, 'no_show' => 0, 'cancelled' => 0, 'postponed' => 0, 'open' => 0,
            'expected' => 0, 'no_show_rate' => null, 'completion_rate' => null,
        ];
    }

    /**
     * The statuses this report treats as an attendance opportunity — used by the dashboard's tile.
     *
     * @return array<int, string>
     */
    public static function attendanceStatuses(): array
    {
        return array_values(array_diff(SerialStatus::values(), [SerialStatus::Cancelled->value, SerialStatus::Postponed->value]));
    }
}
