<?php

declare(strict_types=1);

namespace Tests\Unit\Reports;

use App\Domain\Reports\Data\ReportFilters;
use App\Domain\Reports\Enums\PeakMetric;
use App\Domain\Reports\Enums\ReportKind;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Pure: no container, no database (CONVENTIONS §6.2). Anything that reads the tenant's timezone through
 * `Clock` needs a booted app, so those assertions live in `Tests\Feature\Reports\TimezoneTest` instead.
 */
final class ReportFiltersTest extends TestCase
{
    private function range(string $from, string $to): ReportFilters
    {
        return new ReportFilters(
            from: CarbonImmutable::parse($from, 'Asia/Dhaka')->startOfDay(),
            to: CarbonImmutable::parse($to, 'Asia/Dhaka')->startOfDay(),
        );
    }

    public function test_a_range_is_inclusive_of_both_days(): void
    {
        $this->assertSame(1, $this->range('2026-03-01', '2026-03-01')->days());
        $this->assertSame(10, $this->range('2026-03-01', '2026-03-10')->days());
        $this->assertSame(31, $this->range('2026-03-01', '2026-03-31')->days());
    }

    public function test_granularity_keeps_a_chart_readable(): void
    {
        $this->assertSame('day', $this->range('2026-03-01', '2026-03-31')->granularity());
        $this->assertSame('day', $this->range('2026-01-01', '2026-04-02')->granularity());     // 92 days
        $this->assertSame('week', $this->range('2026-01-01', '2026-04-03')->granularity());    // 93 days
        $this->assertSame('week', $this->range('2025-01-01', '2026-12-31')->granularity());    // 730 days
        $this->assertSame('month', $this->range('2024-01-01', '2026-12-31')->granularity());
    }

    public function test_billing_filters_drop_nulls_so_billings_empty_checks_behave(): void
    {
        $bare = $this->range('2026-03-01', '2026-03-31');
        $this->assertSame([], $bare->billingFilters());

        $scoped = new ReportFilters(from: $bare->from, to: $bare->to, branchId: 3, doctorId: 5, method: 'cash');
        $this->assertSame(['branch_id' => 3, 'doctor_id' => 5, 'method' => 'cash'], $scoped->billingFilters());
    }

    public function test_report_kinds_map_to_pages_and_gates(): void
    {
        $this->assertSame('Reports/WaitTimes', ReportKind::WaitTimes->page());
        $this->assertSame('Reports/PeakHours', ReportKind::PeakHours->page());
        $this->assertSame('reports.wait_times.title', ReportKind::WaitTimes->titleKey());
        $this->assertTrue(ReportKind::Revenue->isFinancial());
        $this->assertFalse(ReportKind::Revenue->isClinical());
        $this->assertTrue(ReportKind::Clinical->isClinical());
        $this->assertFalse(ReportKind::Appointments->isFinancial());
    }

    public function test_the_peak_metric_names_the_column_it_reads(): void
    {
        $this->assertSame('checked_in_at', PeakMetric::Arrivals->column());
        $this->assertSame('booked_at', PeakMetric::Bookings->column());
        $this->assertSame('called_at', PeakMetric::Consultations->column());
    }
}
