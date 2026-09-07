<?php

declare(strict_types=1);

namespace Tests\Feature\Reports;

use App\Domain\Prescription\Enums\VisitStatus;
use App\Domain\Reports\Data\ReportFilters;
use App\Domain\Reports\Queries\PatientMixQuery;
use App\Domain\Reports\Queries\PeakHourQuery;
use App\Domain\Reports\Queries\TopDiagnosesQuery;
use App\Domain\Reports\Support\LocalTime;
use App\Domain\Scheduling\Enums\SessionStatus;
use App\Domain\Serials\Enums\SerialSource;
use App\Domain\Serials\Enums\SerialStatus;
use App\Models\Tenant\Branch;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\Patient;
use App\Models\Tenant\Visit;
use App\Support\Clock;
use Carbon\CarbonImmutable;
use Tests\Feature\Reports\Concerns\ReportFixture;
use Tests\TestCase;

/**
 * Every timestamp in the database is UTC; every number a clinic reads is Asia/Dhaka (+06:00). The whole class
 * is about the six hours in between, because that is where a report silently moves an evening OPD into
 * tomorrow and nobody notices until the month totals disagree with the cash drawer.
 */
final class TimezoneTest extends TestCase
{
    use ReportFixture;

    protected function setUp(): void
    {
        parent::setUp();
        $this->asTenant('a');
        $this->freezeClinic();
        $this->main = Branch::query()->where('is_main', true)->firstOrFail();
        $this->rahman = Doctor::factory()->complete()->create(['slug' => 'dr-tz', 'code' => 'TZ01']);
    }

    protected function tearDown(): void
    {
        Clock::unfreeze();
        parent::tearDown();
    }

    public function test_the_tenant_reports_in_dhaka(): void
    {
        $this->assertSame('Asia/Dhaka', Clock::timezone());
    }

    public function test_a_visit_at_2330_dhaka_belongs_to_that_local_day_not_the_next_utc_one(): void
    {
        $patient = Patient::factory()->create();
        $late = $this->visitAt($patient, '2026-03-01 23:30');

        // Stored as 17:30 UTC on the SAME date here, but the point is the local day, not the stored one.
        $this->assertSame('2026-03-01 17:30:00', $late->started_at->utc()->format('Y-m-d H:i:s'));

        $oneDay = ReportFilters::forDays('2026-03-01', '2026-03-01');
        $this->assertSame(1, app(PatientMixQuery::class)->newVsReturning($oneDay)['patients']);

        // And it is NOT in the following day's report.
        $next = ReportFilters::forDays('2026-03-02', '2026-03-02');
        $this->assertSame(0, app(PatientMixQuery::class)->newVsReturning($next)['patients']);
    }

    public function test_a_visit_at_0030_dhaka_belongs_to_the_new_local_day_although_it_is_still_yesterday_in_utc(): void
    {
        $patient = Patient::factory()->create();
        $early = $this->visitAt($patient, '2026-03-02 00:30');

        // 18:30 UTC on 1 March — a report grouping on the raw UTC day would file this under 1 March.
        $this->assertSame('2026-03-01 18:30:00', $early->started_at->utc()->format('Y-m-d H:i:s'));

        $this->assertSame(0, app(PatientMixQuery::class)->newVsReturning(ReportFilters::forDays('2026-03-01', '2026-03-01'))['patients']);
        $this->assertSame(1, app(PatientMixQuery::class)->newVsReturning(ReportFilters::forDays('2026-03-02', '2026-03-02'))['patients']);
    }

    public function test_a_date_range_filter_is_inclusive_of_both_local_boundaries(): void
    {
        // The first instant of the range, the last instant of it, and one second outside on each side.
        $this->visitAt(Patient::factory()->create(), '2026-03-01 00:00');
        $this->visitAt(Patient::factory()->create(), '2026-03-03 23:59:59');
        $this->visitAt(Patient::factory()->create(), '2026-02-28 23:59:59');
        $this->visitAt(Patient::factory()->create(), '2026-03-04 00:00');

        $range = ReportFilters::forDays('2026-03-01', '2026-03-03');

        // Exactly the two inside; the first second and the last second of the local window both count.
        $this->assertSame(2, app(PatientMixQuery::class)->newVsReturning($range)['patients']);

        // The window itself: 1 Mar 00:00 +06 is 28 Feb 18:00Z, and the exclusive end is 3 Mar 18:00Z.
        $this->assertSame('2026-02-28 18:00:00', $range->startUtc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-03-03 18:00:00', $range->endUtc()->format('Y-m-d H:i:s'));
    }

    public function test_a_2330_arrival_is_the_late_hour_of_its_own_local_weekday_in_the_heatmap(): void
    {
        $session = $this->sessionOn($this->rahman, $this->main, '2026-03-01', '18:00', '23:59', SessionStatus::Closed, '18:00', '23:59');
        $this->patients['PZ'] = Patient::factory()->create();
        $this->serial($session, 1, 'PZ', SerialSource::Counter, SerialStatus::CheckedIn, '2026-03-01 23:30');

        $summary = app(PeakHourQuery::class)->summary(ReportFilters::forDays('2026-03-01', '2026-03-01'));

        // Sunday 23:00 — not Monday, and not 17:00.
        $this->assertSame(1, $summary['grid'][0][23]);
        $this->assertSame(0, $summary['grid'][1][17]);
        $this->assertSame(0, $summary['grid'][0][17]);
    }

    public function test_a_diagnosis_written_late_at_night_stays_on_its_own_local_day(): void
    {
        $patient = Patient::factory()->create();
        Visit::factory()->create([
            'patient_id' => $patient->id,
            'doctor_id' => $this->rahman->id,
            'branch_id' => $this->main->id,
            'status' => VisitStatus::Closed,
            'started_at' => CarbonImmutable::parse('2026-03-01 23:30', 'Asia/Dhaka')->utc(),
            'diagnoses' => [['icd10_code' => 'J06.9', 'title' => 'URTI', 'kind' => 'final', 'sort' => 0]],
        ]);

        $trend = app(TopDiagnosesQuery::class)->trend(ReportFilters::forDays('2026-03-01', '2026-03-01'), ['J06.9']);

        $this->assertSame([['period' => '2026-03-01', 'key' => 'J06.9', 'visits' => 1]], $trend);
    }

    public function test_the_fingerprint_is_stable_and_discriminating(): void
    {
        $a = ReportFilters::forDays('2026-03-01', '2026-03-31');
        $b = ReportFilters::forDays('2026-03-01', '2026-03-31');

        $this->assertSame($a->fingerprint(), $b->fingerprint());
        $this->assertNotSame($a->fingerprint(), $a->withDoctor(7)->fingerprint());
        $this->assertNotSame($a->fingerprint(), ReportFilters::forDays('2026-03-01', '2026-03-30')->fingerprint());
    }

    public function test_local_time_expressions_are_the_same_text_in_select_and_group_by(): void
    {
        // Postgres matches GROUP BY expressions syntactically, so the two must be character-identical.
        $this->assertSame(LocalTime::day('v.started_at'), LocalTime::bucket('v.started_at', 'day'));
        $this->assertSame(LocalTime::month('v.started_at'), LocalTime::bucket('v.started_at', 'month'));
        $this->assertStringContainsString("at time zone 'Asia/Dhaka'", LocalTime::day('v.started_at'));
        // A `date` column is already clinic-local and must never be converted again.
        $this->assertStringNotContainsString('at time zone', LocalTime::dateBucket('si.session_date', 'day'));
    }

    public function test_billing_gets_an_inclusive_upper_bound_because_it_compares_with_between(): void
    {
        $march = ReportFilters::forDays('2026-03-01', '2026-03-31');

        $this->assertSame('2026-03-31 18:00:00', $march->endUtc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-03-31 17:59:59', $march->endInclusiveUtc()->format('Y-m-d H:i:s'));
    }

    private function visitAt(Patient $patient, string $localDateTime): Visit
    {
        return Visit::factory()->create([
            'patient_id' => $patient->id,
            'doctor_id' => $this->rahman->id,
            'branch_id' => $this->main->id,
            'status' => VisitStatus::Closed,
            'started_at' => CarbonImmutable::parse($localDateTime, 'Asia/Dhaka')->utc(),
            'diagnoses' => [],
        ]);
    }
}
