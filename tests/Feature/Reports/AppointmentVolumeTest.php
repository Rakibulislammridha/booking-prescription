<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Domain\Reports\Data\ReportFilters;
use App\Domain\Reports\Queries\AppointmentVolumeQuery;
use App\Models\Tenant\Serial;
use App\Support\Clock;
use Tests\Feature\Reports\Concerns\ReportFixture;
use Tests\TestCase;

/**
 * The numbers in every assertion below are worked out by hand from the fixture's table (see ReportFixture's
 * docblock), not read back from the implementation.
 */
final class AppointmentVolumeTest extends TestCase
{
    use ReportFixture;

    private AppointmentVolumeQuery $query;

    protected function setUp(): void
    {
        parent::setUp();
        $this->asTenant('a');
        $this->seedReportFixture();
        $this->query = app(AppointmentVolumeQuery::class);
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

    public function test_totals_count_every_issued_serial_and_exclude_cancelled_from_the_rate(): void
    {
        $totals = $this->query->summary($this->march())['totals'];

        // 6 (1 Mar) + 3 (2 Mar) + 2 (cancelled session, 3 Mar) = 11 serials issued.
        $this->assertSame(11, $totals['booked']);
        $this->assertSame(6, $totals['completed']);
        $this->assertSame(1, $totals['no_show']);
        // One transferred-away serial plus the two the cancelled session took down.
        $this->assertSame(3, $totals['cancelled']);
        $this->assertSame(1, $totals['open']);
        // 11 − 3 cancelled − 0 postponed: the attendance opportunities.
        $this->assertSame(8, $totals['expected']);
        $this->assertSame(12.5, $totals['no_show_rate']);   // 1 / 8
        $this->assertSame(75.0, $totals['completion_rate']); // 6 / 8
    }

    public function test_a_reinstated_no_show_is_not_counted_because_the_status_is_read_now(): void
    {
        $before = $this->query->summary($this->march())['totals']['no_show'];
        $this->assertSame(1, $before);

        Serial::query()
            ->where('status', 'no_show')
            ->firstOrFail()
            ->forceFill(['status' => 'checked_in', 'reinstated_at' => now(), 'checked_in_at' => now()])
            ->save();

        $this->assertSame(0, $this->query->summary($this->march())['totals']['no_show']);
    }

    public function test_by_doctor_splits_the_transferred_serial_across_both_doctors(): void
    {
        $rows = $this->rows($this->query->summary($this->march())['by_doctor'])->keyBy('doctor_id');

        // Dr Rahman: 6 on 1 March + 2 on the cancelled 3 March.
        $this->assertSame(8, $rows[$this->rahman->id]['booked']);
        $this->assertSame(4, $rows[$this->rahman->id]['completed']);
        $this->assertSame(1, $rows[$this->rahman->id]['no_show']);
        $this->assertSame(3, $rows[$this->rahman->id]['cancelled']);

        // The serial transferred away is cancelled under Rahman and live under Sultana — both are true.
        $this->assertSame(3, $rows[$this->sultana->id]['booked']);
        $this->assertSame(2, $rows[$this->sultana->id]['completed']);
        $this->assertSame(0, $rows[$this->sultana->id]['cancelled']);
    }

    public function test_sources_fold_into_the_online_versus_counter_split_the_brief_asks_for(): void
    {
        $summary = $this->query->summary($this->march());
        $sources = $this->rows($summary['by_source'])->keyBy('source');

        $this->assertSame(6, $sources['counter']['booked']);   // 3 on 1 Mar + 1 on 2 Mar + 2 on 3 Mar
        $this->assertSame(2, $sources['online']['booked']);
        $this->assertSame(1, $sources['walkin']['booked']);
        $this->assertSame(1, $sources['kiosk']['booked']);
        $this->assertSame(1, $sources['followup']['booked']);

        $groups = $this->rows($summary['by_channel_group'])->keyBy('channel_group');
        $this->assertSame(3, $groups['online']['booked']);     // online + kiosk = patient self-service
        $this->assertSame(7, $groups['counter']['booked']);    // counter + walk-in = the desk
        $this->assertSame(1, $groups['followup']['booked']);
        $this->assertSame(11, $groups->sum('booked'));
    }

    public function test_by_period_buckets_on_the_clinic_local_session_date(): void
    {
        $rows = $this->rows($this->query->byPeriod($this->march()))->keyBy('period');

        $this->assertSame(6, $rows['2026-03-01']['booked']);
        $this->assertSame(3, $rows['2026-03-02']['booked']);
        $this->assertSame(2, $rows['2026-03-03']['booked']);
        $this->assertNull($rows['2026-03-03']['no_show_rate'], 'a wholly cancelled day has no attendance opportunity, so no rate');
        $this->assertCount(3, $rows);
    }

    public function test_a_long_range_buckets_by_week_then_by_month_so_a_chart_never_gets_a_thousand_points(): void
    {
        // A year: weekly buckets, labelled by the Monday that opens them — 1 March 2026 is a Sunday, so it
        // belongs to the week beginning 23 February.
        $weekly = $this->rows($this->query->byPeriod(ReportFilters::forDays('2025-03-01', '2026-03-10')))->keyBy('period');
        $this->assertSame(6, $weekly['2026-02-23']['booked']);
        $this->assertSame(5, $weekly['2026-03-02']['booked']);

        // Past two years: monthly.
        $monthly = $this->rows($this->query->byPeriod(ReportFilters::forDays('2024-01-01', '2026-03-10')))->keyBy('period');
        $this->assertSame(11, $monthly['2026-03']['booked']);
        $this->assertCount(1, $monthly);
    }

    public function test_the_branch_filter_narrows_to_one_branch(): void
    {
        $second = new ReportFilters(
            from: $this->march()->from,
            to: $this->march()->to,
            branchId: $this->second->id,
        );

        $this->assertSame(0, $this->query->summary($second)['totals']['booked']);
        $this->assertSame(11, $this->query->summary($this->march()->withRange($this->march()->from, $this->march()->to))['totals']['booked']);
    }

    public function test_the_doctor_filter_narrows_to_one_doctor(): void
    {
        $rahman = $this->march()->withDoctor($this->rahman->id);

        $this->assertSame(8, $this->query->summary($rahman)['totals']['booked']);
    }
}
