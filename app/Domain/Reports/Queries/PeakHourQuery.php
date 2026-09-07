<?php

declare(strict_types=1);

namespace App\Domain\Reports\Queries;

use App\Domain\Reports\Data\ReportFilters;
use App\Domain\Reports\Support\LocalTime;
use App\Domain\Reports\Support\Row;
use Illuminate\Support\Facades\DB;

/**
 * The peak-hour heatmap for staffing decisions (BRIEF §5.L, last line).
 *
 * DEFINITIONS:
 *  - The default metric is ARRIVALS (`serials.checked_in_at`): staffing follows the moment a patient is
 *    physically at the desk, not the moment he booked from his phone at midnight. `bookings` (`booked_at`) and
 *    `consultations` (`called_at`) are offered as an explicit switch rather than silently conflated.
 *  - Weekday is 0 = Sunday … 6 = Saturday, the same numbering `doctor_schedules.weekday` uses, and both weekday
 *    and hour are the CLINIC-LOCAL ones: a patient who checks in at 23:30 Dhaka on Sunday is Sunday 23:00, not
 *    Monday 17:00 UTC.
 *  - `busiest` reports the single hottest cell and `daily_peak` the hottest hour of each weekday, because that
 *    is the sentence a manager actually writes on the rota ("Fridays, put two people on the desk at 10").
 *  - Averages are per OCCURRENCE of that weekday inside the range, so a range of five Sundays and four Mondays
 *    does not make Sunday look busier than it is.
 *
 * INDEXES: driven off `session_instances.session_date` (`session_instances_session_date_branch_id_idx`, or the
 * doctor-leading index under a filter), then `serials_session_instance_id_status_idx` for the nested loop. The
 * timezone conversion is applied to the SELECTed timestamp only, never to the range predicate, so the date
 * index is still usable — that is why the range is expressed on `session_date` and not on `checked_in_at`.
 */
final class PeakHourQuery
{
    public const HOURS = 24;

    public const WEEKDAYS = 7;

    /** @return array<string, mixed> */
    public function summary(ReportFilters $filters): array
    {
        $cells = $this->cells($filters);
        $grid = $this->grid($cells);

        return [
            'metric' => $filters->metric->value,
            'cells' => $cells,
            'grid' => $grid,
            'weekday_occurrences' => $this->weekdayOccurrences($filters),
            'busiest' => $this->busiest($cells),
            'daily_peak' => $this->dailyPeak($cells),
            'total' => array_sum(array_column($cells, 'count')),
            'max' => $cells === [] ? 0 : max(array_column($cells, 'count')),
        ];
    }

    /** @return array<int, array{weekday: int, hour: int, count: int}> */
    public function cells(ReportFilters $filters): array
    {
        $column = 's.'.$filters->metric->column();
        $weekday = LocalTime::weekday($column);
        $hour = LocalTime::hour($column);

        $query = DB::table('serials as s')
            ->join('session_instances as si', 'si.id', '=', 's.session_instance_id')
            ->whereBetween('si.session_date', [$filters->fromDate(), $filters->toDate()])
            ->whereNotNull(DB::raw($column));

        if ($filters->branchId !== null) {
            $query->where('si.branch_id', $filters->branchId);
        }

        if ($filters->doctorId !== null) {
            $query->where('si.doctor_id', $filters->doctorId);
        }

        return $query
            ->selectRaw("{$weekday} as weekday, {$hour} as hour, count(*) as n")
            ->groupBy(DB::raw($weekday), DB::raw($hour))
            ->orderBy('weekday')
            ->orderBy('hour')
            ->get()
            ->map(fn (mixed $row): array => [
                'weekday' => Row::int($row, 'weekday'),
                'hour' => Row::int($row, 'hour'),
                'count' => Row::int($row, 'n'),
            ])
            ->all();
    }

    /**
     * A dense 7 × 24 matrix — the client draws a heatmap, not a sparse scatter, and building the zeroes here
     * keeps the page component free of grid arithmetic.
     *
     * @param  array<int, array{weekday: int, hour: int, count: int}>  $cells
     * @return array<int, array<int, int>>
     */
    public function grid(array $cells): array
    {
        $grid = array_fill(0, self::WEEKDAYS, array_fill(0, self::HOURS, 0));

        foreach ($cells as $cell) {
            if ($cell['weekday'] >= 0 && $cell['weekday'] < self::WEEKDAYS && $cell['hour'] >= 0 && $cell['hour'] < self::HOURS) {
                $grid[$cell['weekday']][$cell['hour']] = $cell['count'];
            }
        }

        return $grid;
    }

    /**
     * How many times each weekday occurs in the range, so a cell can be read as "per Sunday" rather than as a
     * raw total that depends on the length of the range.
     *
     * @return array<int, int>
     */
    public function weekdayOccurrences(ReportFilters $filters): array
    {
        $counts = array_fill(0, self::WEEKDAYS, 0);
        $day = $filters->from;

        for ($i = 0; $i < $filters->days(); $i++) {
            $counts[$day->dayOfWeek] = ($counts[$day->dayOfWeek] ?? 0) + 1;
            $day = $day->addDay();
        }

        return $counts;
    }

    /**
     * @param  array<int, array{weekday: int, hour: int, count: int}>  $cells
     * @return array{weekday: int, hour: int, count: int}|null
     */
    public function busiest(array $cells): ?array
    {
        $best = null;

        foreach ($cells as $cell) {
            if ($best === null || $cell['count'] > $best['count']) {
                $best = $cell;
            }
        }

        return $best;
    }

    /**
     * @param  array<int, array{weekday: int, hour: int, count: int}>  $cells
     * @return array<int, array{weekday: int, hour: int|null, count: int, total: int}>
     */
    public function dailyPeak(array $cells): array
    {
        $out = [];

        for ($weekday = 0; $weekday < self::WEEKDAYS; $weekday++) {
            $out[$weekday] = ['weekday' => $weekday, 'hour' => null, 'count' => 0, 'total' => 0];
        }

        foreach ($cells as $cell) {
            $weekday = $cell['weekday'];

            if (! isset($out[$weekday])) {
                continue;
            }

            $out[$weekday]['total'] += $cell['count'];

            if ($cell['count'] > $out[$weekday]['count']) {
                $out[$weekday]['hour'] = $cell['hour'];
                $out[$weekday]['count'] = $cell['count'];
            }
        }

        return array_values($out);
    }
}
