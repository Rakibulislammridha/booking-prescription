<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Domain\Clinic\Enums\Role;
use App\Domain\Reports\Data\ReportFilters;
use App\Domain\Reports\Enums\ReportKind;
use App\Domain\Reports\Services\ReportCache;
use App\Domain\Reports\Services\ReportData;
use App\Domain\Reports\Services\ReportScopeResolver;
use App\Domain\Scheduling\Enums\SessionStatus;
use App\Domain\Serials\Enums\SerialSource;
use App\Domain\Serials\Enums\SerialStatus;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\Patient;
use App\Support\Clock;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\Reports\Concerns\ReportFixture;
use Tests\TestCase;

/**
 * The pages themselves: the right component, the right props, a BOUNDED query count (a report page that grows
 * a query per doctor is a report page that times out on the clinic it was built for), and a cache that never
 * presents a stale number as a live one.
 */
final class ReportPageTest extends TestCase
{
    use ReportFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->asTenant('a');
        $this->actingAsStaff(Role::HospitalAdmin);
        $this->seedReportFixture();
    }

    protected function tearDown(): void
    {
        Clock::unfreeze();
        parent::tearDown();
    }

    public function test_every_report_page_renders_its_component_with_the_shared_props(): void
    {
        $expected = [
            'appointments' => 'Reports/Appointments',
            'wait-times' => 'Reports/WaitTimes',
            'revenue' => 'Reports/Revenue',
            'patients' => 'Reports/Patients',
            'clinical' => 'Reports/Clinical',
            'peak-hours' => 'Reports/PeakHours',
        ];

        foreach ($expected as $report => $component) {
            $this->get(route('panel.reports.show', ['report' => $report, 'from' => '2026-03-01', 'to' => '2026-03-10'], false))
                ->assertInertia(fn (AssertableInertia $page) => $page
                    ->component($component)
                    ->where('report', $report)
                    ->where('filters.from', '2026-03-01')
                    ->where('filters.to', '2026-03-10')
                    ->has('scope')
                    ->has('options.branches')
                    ->has('options.doctors')
                    ->has('data')
                    ->has('generated_at'));
        }

        // Every ReportKind has a page component, so a new family cannot ship without one.
        $this->assertSame(array_keys($expected), array_values(array_diff(ReportKind::values(), ['dashboard'])));
    }

    public function test_an_unknown_report_segment_404s_at_the_router(): void
    {
        $this->get('/panel/reports/salaries')->assertNotFound();
    }

    public function test_a_report_page_costs_a_bounded_number_of_queries_however_many_doctors_there_are(): void
    {
        $count = fn (string $report): int => $this->countQueries(fn () => $this->get(
            route('panel.reports.show', ['report' => $report, 'from' => '2026-03-01', 'to' => '2026-03-10'], false),
        )->assertOk());

        // Warm the request-level caches (permissions, settings) so the comparison is about the REPORT's own
        // queries and not about the first request of the process.
        $count('appointments');
        app(ReportCache::class)->flushTenant();
        $before = $count('appointments');

        // Ten more doctors, each with their own session and serials: an N+1 would grow the count with them.
        for ($i = 0; $i < 10; $i++) {
            $doctor = Doctor::factory()->complete()->create(['slug' => "dr-load-{$i}", 'code' => "LD{$i}"]);
            $session = $this->sessionOn($doctor, $this->main, '2026-03-04', '09:00', '13:00', SessionStatus::Closed, '09:00', '13:00');
            $this->patients["L{$i}"] = Patient::factory()->create();
            $this->serial($session, 1, "L{$i}", SerialSource::Counter, SerialStatus::Completed, '2026-03-04 09:05', '2026-03-04 09:20', '2026-03-04 09:30');
        }

        app(ReportCache::class)->flushTenant();
        $after = $count('appointments');

        $this->assertSame($before, $after, "the appointments page went from {$before} to {$after} queries after adding ten doctors");
        $this->assertLessThanOrEqual(20, $after, 'a report page must not need dozens of round trips');
    }

    public function test_the_clinical_page_is_bounded_too(): void
    {
        $first = $this->countQueries(fn () => $this->get(route('panel.reports.show', ['report' => 'clinical', 'from' => '2026-03-01', 'to' => '2026-03-10'], false))->assertOk());
        $this->assertLessThanOrEqual(20, $first);
    }

    public function test_the_dashboard_and_its_json_poll_agree(): void
    {
        $page = $this->get(route('panel.reports.index', [], false));
        $page->assertInertia(fn (AssertableInertia $p) => $p->component('Reports/Dashboard')->has('data.live')->has('data.sessions')->has('trend'));

        $json = $this->getJson(route('api.reports.dashboard', [], false))->assertOk()->json();

        $this->assertSame(
            $page->viewData('page')['props']['data']['appointments'],
            $json['data']['appointments'],
            'the poll must return the numbers the page was rendered from',
        );
    }

    public function test_a_cached_payload_says_when_it_was_computed_and_that_it_is_cached(): void
    {
        $scope = app(ReportScopeResolver::class)->for($this->app['auth']->guard('web')->user());
        $filters = ReportFilters::forDays('2026-03-01', '2026-03-10');

        $first = app(ReportData::class)->for(ReportKind::Appointments, $filters, $scope);
        $this->assertFalse($first['cached']);

        $second = app(ReportData::class)->for(ReportKind::Appointments, $filters, $scope);
        $this->assertTrue($second['cached'], 'the second read is served from cache');
        $this->assertSame($first['generated_at'], $second['generated_at'], 'and it reports the ORIGINAL computation time, never "now"');

        // A different filter is a different key, so a branch switch is never served someone else's numbers.
        $other = app(ReportData::class)->for(ReportKind::Appointments, $filters->withDoctor($this->rahman->id), $scope);
        $this->assertFalse($other['cached']);

        app(ReportCache::class)->flushTenant();
        $this->assertFalse(app(ReportData::class)->for(ReportKind::Appointments, $filters, $scope)['cached']);
    }

    public function test_a_range_that_includes_today_is_cached_for_less_time_than_a_closed_one(): void
    {
        $cache = app(ReportCache::class);

        $this->assertSame(ReportCache::TTL_LIVE_SECONDS, $cache->ttl(ReportFilters::forDays('2026-03-01', '2026-03-10')));
        $this->assertSame(ReportCache::TTL_HISTORIC_SECONDS, $cache->ttl(ReportFilters::forDays('2026-01-01', '2026-01-31')));
    }

    private function countQueries(callable $callback): int
    {
        DB::flushQueryLog();
        DB::enableQueryLog();
        $callback();
        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $queries;
    }
}
