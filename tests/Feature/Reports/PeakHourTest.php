<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Domain\Reports\Data\ReportFilters;
use App\Domain\Reports\Enums\PeakMetric;
use App\Domain\Reports\Queries\PeakHourQuery;
use App\Support\Clock;
use Tests\Feature\Reports\Concerns\ReportFixture;
use Tests\TestCase;

/**
 * 1 March 2026 is a SUNDAY (weekday 0) and 2 March a Monday (weekday 1). Arrivals on 1 March are 09:05, 09:15,
 * 10:00 and 11:00 Dhaka; on 2 March 09:10 and 09:30.
 */
final class PeakHourTest extends TestCase
{
    use ReportFixture;

    private PeakHourQuery $query;

    protected function setUp(): void
    {
        parent::setUp();
        $this->asTenant('a');
        $this->seedReportFixture();
        $this->query = app(PeakHourQuery::class);
    }

    protected function tearDown(): void
    {
        Clock::unfreeze();
        parent::tearDown();
    }

    private function march(PeakMetric $metric = PeakMetric::Arrivals): ReportFilters
    {
        $range = ReportFilters::forDays('2026-03-01', '2026-03-10');

        return new ReportFilters(from: $range->from, to: $range->to, metric: $metric);
    }

    public function test_arrivals_land_on_the_clinic_local_weekday_and_hour(): void
    {
        $summary = $this->query->summary($this->march());

        $this->assertSame(6, $summary['total']);
        $this->assertSame(2, $summary['max']);
        $this->assertSame(2, $summary['grid'][0][9]);   // Sunday 09:00 — two arrivals
        $this->assertSame(1, $summary['grid'][0][10]);
        $this->assertSame(1, $summary['grid'][0][11]);
        $this->assertSame(2, $summary['grid'][1][9]);   // Monday 09:00
        $this->assertSame(0, $summary['grid'][6][9]);   // Saturday: nothing
    }

    public function test_the_busiest_cell_and_the_daily_peak_are_what_a_rota_is_written_from(): void
    {
        $summary = $this->query->summary($this->march());

        $this->assertContains($summary['busiest']['weekday'], [0, 1]);
        $this->assertSame(9, $summary['busiest']['hour']);
        $this->assertSame(2, $summary['busiest']['count']);

        $peaks = $this->rows($summary['daily_peak'])->keyBy('weekday');
        $this->assertSame(4, $peaks[0]['total']);
        $this->assertSame(9, $peaks[0]['hour']);
        $this->assertSame(2, $peaks[1]['total']);
        $this->assertNull($peaks[6]['hour']);
    }

    public function test_the_metric_switch_counts_a_different_moment(): void
    {
        // Bookings were all stamped a day before their session's planned start, so they fall on other weekdays.
        $bookings = $this->query->summary($this->march(PeakMetric::Bookings));
        $this->assertSame(11, $bookings['total'], 'every serial has a booked_at');

        // Consultations = the moment the patient was called: six of them.
        $consultations = $this->query->summary($this->march(PeakMetric::Consultations));
        $this->assertSame(6, $consultations['total']);
    }

    public function test_weekday_occurrences_let_a_cell_be_read_per_sunday(): void
    {
        // 1–10 March 2026 contains two Sundays (1st and 8th) and two Mondays (2nd and 9th).
        $occurrences = $this->query->weekdayOccurrences($this->march());

        $this->assertSame(2, $occurrences[0]);
        $this->assertSame(2, $occurrences[1]);
        $this->assertSame(1, $occurrences[3]);   // Wednesday 4 March only
        $this->assertSame(10, array_sum($occurrences));
    }
}
