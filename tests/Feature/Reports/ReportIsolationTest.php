<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Domain\Clinic\Enums\Role;
use App\Domain\Reports\Data\ReportFilters;
use App\Domain\Reports\Enums\ExportFormat;
use App\Domain\Reports\Enums\ReportKind;
use App\Domain\Reports\Export\ReportExporter;
use App\Domain\Reports\Jobs\GenerateReportExport;
use App\Domain\Reports\Queries\AppointmentVolumeQuery;
use App\Domain\Reports\Services\ReportCache;
use App\Domain\Reports\Services\ReportScopeResolver;
use App\Support\Clock;
use Tests\Feature\Reports\Concerns\ReportFixture;
use Tests\TestCase;

/** Tenant isolation (BRIEF §8): no report and no cached report may reach another clinic's schema. */
final class ReportIsolationTest extends TestCase
{
    use ReportFixture;

    protected function tearDown(): void
    {
        Clock::unfreeze();
        parent::tearDown();
    }

    public function test_report_exports_are_isolated(): void
    {
        $this->assertTenantIsolated('report_exports', function (): void {
            $this->actingAsStaff(Role::HospitalAdmin);
            $this->seedReportFixture();
            $scope = app(ReportScopeResolver::class)->for($this->app['auth']->guard('web')->user());
            $filters = ReportFilters::forDays('2026-03-01', '2026-03-10');
            $exporter = app(ReportExporter::class);

            $exporter->queue(
                ReportKind::Appointments,
                ExportFormat::Csv,
                $filters,
                $scope,
                $this->app['auth']->guard('web')->user(),
                $exporter->table(ReportKind::Appointments, $filters, $scope),
            );
        });
    }

    public function test_one_clinics_numbers_never_appear_in_another_clinics_report(): void
    {
        $this->asTenant('a');
        $this->seedReportFixture();
        $march = ReportFilters::forDays('2026-03-01', '2026-03-10');

        $this->assertSame(11, app(AppointmentVolumeQuery::class)->summary($march)['totals']['booked']);

        $this->asTenant('b');
        $this->assertSame(0, app(AppointmentVolumeQuery::class)->summary($march)['totals']['booked']);
    }

    public function test_the_cache_key_is_tenant_scoped_so_a_warm_cache_cannot_leak(): void
    {
        $cache = app(ReportCache::class);
        $march = ReportFilters::forDays('2026-03-01', '2026-03-10');

        $this->asTenant('a');
        $keyA = $cache->key('appointments', $march);
        $cache->remember('appointments', $march, fn (): array => ['booked' => 11]);

        $this->asTenant('b');
        $keyB = $cache->key('appointments', $march);

        $this->assertNotSame($keyA, $keyB);
        $this->assertStringContainsString(':9001:', $keyA);
        $this->assertStringContainsString(':9002:', $keyB);

        // Tenant B computes its own value; it cannot be handed A's.
        $payload = $cache->remember('appointments', $march, fn (): array => ['booked' => 0]);
        $this->assertFalse($payload['cached']);
        $this->assertSame(['booked' => 0], $payload['data']);
    }

    public function test_a_queued_export_carries_its_tenant(): void
    {
        $this->asTenant('a');
        $this->actingAsStaff(Role::HospitalAdmin);
        $this->seedReportFixture();

        $scope = app(ReportScopeResolver::class)->for($this->app['auth']->guard('web')->user());
        $job = new GenerateReportExport(1, $scope->toArray());

        $this->assertSame(9001, $job->tenantId);
        $this->assertSame('reports', $job->queue);
        $this->assertSame(['tenant:9001'], $job->tags());
    }
}
