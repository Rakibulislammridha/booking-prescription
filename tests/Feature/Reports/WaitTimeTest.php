<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Domain\Reports\Data\ReportFilters;
use App\Domain\Reports\Queries\WaitTimeQuery;
use App\Domain\Serials\Services\ConsultAverage;
use App\Support\Clock;
use Tests\Feature\Reports\Concerns\ReportFixture;
use Tests\TestCase;

/**
 * Waits: 15, 20, 30, 5 (1 Mar) and 10, 15 (2 Mar) minutes.
 * Consultations: 12, 6, 3 (1 Mar) and 10, 7 (2 Mar) minutes, plus one four-hour sample the clamp throws out.
 */
final class WaitTimeTest extends TestCase
{
    use ReportFixture;

    private WaitTimeQuery $query;

    protected function setUp(): void
    {
        parent::setUp();
        $this->asTenant('a');
        $this->seedReportFixture();
        $this->query = app(WaitTimeQuery::class);
    }

    protected function tearDown(): void
    {
        Clock::unfreeze();
        parent::tearDown();
    }

    private function march(): ReportFilters
    {
        return ReportFilters::forDays('2026-03-01', '2026-03-10');
    }

    public function test_wait_is_check_in_to_called_and_a_no_show_contributes_no_sample(): void
    {
        $totals = $this->query->totals($this->march());

        // Six arrivals; the no-show and the never-arrived kiosk booking are absent, not zero.
        $this->assertSame(6, $totals['wait_samples']);
        // (15 + 20 + 30 + 5 + 10 + 15) / 6 = 15.833…
        $this->assertSame(15.8, $totals['wait_avg_minutes']);
        // Sorted 5, 10, 15, 15, 20, 30 → median 15.
        $this->assertSame(15.0, $totals['wait_p50_minutes']);
        // percentile_cont(0.9) over six samples: index 4.5 → halfway between 20 and 30.
        $this->assertSame(25.0, $totals['wait_p90_minutes']);
    }

    public function test_consultation_uses_the_same_clamp_as_the_live_queue_and_says_what_it_excluded(): void
    {
        $totals = $this->query->totals($this->march());

        // Five accepted samples: 12, 6, 3, 10 and 7 minutes. The four-hour one is outside [30 s, 30 min].
        $this->assertSame(5, $totals['consult_samples']);
        $this->assertSame(1, $totals['consult_excluded']);
        // (720 + 360 + 180 + 600 + 420) / 5 = 456 s = 7.6 min.
        $this->assertSame(7.6, $totals['consult_avg_minutes']);
        $this->assertSame(7.0, $totals['consult_p50_minutes']);

        // And the clamp is literally the ConsultAverage one the ETA already uses.
        $this->assertSame([30, 1800], [WaitTimeQuery::CONSULT_MIN_SECONDS, WaitTimeQuery::CONSULT_MAX_SECONDS]);
        $this->assertSame(ConsultAverage::CLAMP, [WaitTimeQuery::CONSULT_MIN_SECONDS, WaitTimeQuery::CONSULT_MAX_SECONDS]);
    }

    public function test_by_doctor_separates_the_two_books(): void
    {
        $rows = $this->rows($this->query->byDoctor($this->march()))->keyBy('doctor_id');

        $this->assertSame(4, $rows[$this->rahman->id]['wait_samples']);
        $this->assertSame(17.5, $rows[$this->rahman->id]['wait_avg_minutes']);   // (15 + 20 + 30 + 5) / 4
        $this->assertSame(3, $rows[$this->rahman->id]['consult_samples']);
        $this->assertSame(7.0, $rows[$this->rahman->id]['consult_avg_minutes']); // (720 + 360 + 180) / 3 = 420 s

        $this->assertSame(2, $rows[$this->sultana->id]['wait_samples']);
        $this->assertSame(12.5, $rows[$this->sultana->id]['wait_avg_minutes']);  // (10 + 15) / 2
        $this->assertSame(8.5, $rows[$this->sultana->id]['consult_avg_minutes']); // (600 + 420) / 2 = 510 s
    }

    public function test_overrun_measures_closed_sessions_against_their_planned_end(): void
    {
        $overrun = $this->query->overrun($this->march())['totals'];

        // Only the two CLOSED sessions: 1 March ran 25 minutes over, 2 March finished 10 minutes early.
        $this->assertSame(2, $overrun['sessions']);
        $this->assertSame(7.5, $overrun['overrun_avg_minutes']);
        $this->assertSame(25.0, $overrun['overrun_max_minutes']);
        // Only the 25-minute one clears the 15-minute grace.
        $this->assertSame(1, $overrun['overran']);
        $this->assertSame(50.0, $overrun['overran_rate']);
        // Started 10 minutes late on 1 March, on time on 2 March.
        $this->assertSame(5.0, $overrun['late_start_avg_minutes']);
    }

    public function test_a_cancelled_session_is_not_an_overrun(): void
    {
        // The 3 March session is cancelled and has no actual end; it must not appear anywhere in the overrun.
        $rows = $this->query->overrun($this->march())['by_doctor'];

        $this->assertSame(1, $this->rows($rows)->firstWhere('doctor_id', $this->rahman->id)['sessions']);
    }
}
