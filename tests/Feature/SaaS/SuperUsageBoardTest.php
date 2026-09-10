<?php

declare(strict_types=1);

namespace Tests\Feature\SaaS;

use App\Domain\Audit\Enums\CentralAuditAction;
use App\Domain\SaaS\Enums\PlanFeatureKey;
use App\Domain\SaaS\Enums\UsageMetric;
use App\Domain\SaaS\Queries\TenantLimitsSnapshot;
use App\Domain\SaaS\Services\PlanLimits;
use App\Domain\SaaS\Services\UsageMeter;
use App\Models\Central\AuditLogCentral;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\SaaS\Concerns\ControlsPlanLimits;
use Tests\TestCase;

/**
 * The usage board: every clinic against its cap, sorted by share, filtered to over/near; the drill-down with six
 * months of history; the CSV. The numbers are pinned against a fixture, and the board's three-query limit
 * resolver is pinned against `PlanLimits` so the two cannot drift.
 */
final class SuperUsageBoardTest extends TestCase
{
    use ControlsPlanLimits;

    public function test_the_board_sorts_by_share_of_the_cap_and_filters_to_over_and_near(): void
    {
        $this->actingAsSuper();
        $a = $this->tenant('a');
        $b = $this->tenant('b');

        // A: 90 of 100 SMS (near). B: 250 of 200 (over).
        $this->setLimit($a, PlanFeatureKey::SmsCreditsMonthly, 100);
        $this->setUsage($a, UsageMetric::SmsCredits, 90);
        $this->setLimit($b, PlanFeatureKey::SmsCreditsMonthly, 200);
        $this->setUsage($b, UsageMetric::SmsCredits, 250);

        $this->get('/usage?metric=sms_credits')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Super/Usage/Index')
                ->where('metric', 'sms_credits')
                ->where('is_capped', true)
                ->where('filters.filter', 'all')
                ->where('filters.sort', 'percent')
                ->where('counts.over', 1)
                ->where('counts.near', 2)
                ->where('board.0.slug', $b->slug)
                ->where('board.0.value', 250)
                ->where('board.0.limit', 200)
                ->where('board.0.percent', 125)
                ->where('board.0.exhausted', true)
                ->where('board.1.slug', $a->slug)
                ->where('board.1.percent', 90)
                ->where('board.1.near', true)
                ->where('board.1.exhausted', false)
                ->has('series', 12)
                ->has('meta.total'));

        $this->get('/usage?metric=sms_credits&filter=over')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('meta.total', 1)->where('board.0.slug', $b->slug)->where('filters.filter', 'over'));

        $this->get('/usage?metric=sms_credits&filter=near')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('meta.total', 2));

        $this->get('/usage?metric=sms_credits&sort=value')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('board.0.value', 250)->where('filters.sort', 'value'));

        // An unlimited cap sorts last and never counts as near or over.
        $this->setLimit($a, PlanFeatureKey::SmsCreditsMonthly, null);
        $this->get('/usage?metric=sms_credits')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page
                ->where('counts.over', 1)->where('counts.near', 1)
                ->where('board.0.slug', $b->slug)
                ->where('board.1.limit', null)->where('board.1.percent', null));

        // A metric no plan caps is listed by volume.
        $this->get('/usage?metric=prescriptions')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('is_capped', false)->where('counts.over', 0));
    }

    public function test_the_snapshot_resolves_the_same_cap_as_plan_limits_including_overrides_and_add_ons(): void
    {
        $a = $this->tenant('a');
        $b = $this->tenant('b');
        $this->setLimit($a, PlanFeatureKey::Doctors, 12);
        $this->setLimit($b, PlanFeatureKey::Doctors, 0, enabled: false);   // disabled row: cap 0, not unlimited

        $snapshot = app(TenantLimitsSnapshot::class)->forMetric(UsageMetric::Doctors);
        $limits = app(PlanLimits::class);

        $this->assertSame($limits->limit($a, UsageMetric::Doctors), $snapshot[$a->id] ?? null);
        $this->assertSame(12, $snapshot[$a->id] ?? null);
        $this->assertSame($limits->limit($b, UsageMetric::Doctors), $snapshot[$b->id] ?? null);
        $this->assertSame(0, $snapshot[$b->id] ?? null);

        $this->setLimit($a, PlanFeatureKey::Doctors, null);
        $unlimited = app(TenantLimitsSnapshot::class)->forMetric(UsageMetric::Doctors);
        $this->assertArrayHasKey($a->id, $unlimited);
        $this->assertNull($unlimited[$a->id]);
        $this->assertNull($limits->limit($a, UsageMetric::Doctors));
    }

    public function test_the_drill_down_shows_every_metric_against_its_cap_with_six_months_of_history(): void
    {
        $this->actingAsSuper();
        $a = $this->tenant('a');
        $meter = app(UsageMeter::class);
        $month = CarbonImmutable::now('Asia/Dhaka')->startOfMonth();

        $this->setLimit($a, PlanFeatureKey::AppointmentsMonthly, 500);
        $meter->set($a, UsageMetric::Appointments, 425);
        $meter->set($a, UsageMetric::Appointments, 300, period: $month->subMonth()->format('Y-m'));
        $meter->set($a, UsageMetric::Appointments, 120, period: $month->subMonths(5)->format('Y-m'));
        $meter->set($a, UsageMetric::Appointments, 999, period: $month->subMonths(6)->format('Y-m'));   // outside the window
        $this->setLimit($a, PlanFeatureKey::Doctors, 10);
        $meter->set($a, UsageMetric::Doctors, 10);

        $this->get('/usage/'.$a->public_id)->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Super/Usage/Show')
                ->where('tenant.slug', $a->slug)
                ->where('history_months', 6)
                ->where('metrics', function (Collection $metrics) use ($month): bool {
                    $rows = $metrics->keyBy('metric');

                    $appointments = $rows['appointments'];
                    $this->assertSame(425, $appointments['used']);
                    $this->assertSame(500, $appointments['limit']);
                    $this->assertSame(85, $appointments['percent']);
                    $this->assertTrue($appointments['near']);
                    $this->assertFalse($appointments['exhausted']);
                    $this->assertTrue($appointments['capped']);
                    $this->assertFalse($appointments['is_gauge']);
                    $this->assertCount(6, $appointments['history']);
                    $this->assertSame($month->subMonths(5)->format('Y-m'), $appointments['history'][0]['period']);
                    $this->assertSame(120, $appointments['history'][0]['value']);
                    $this->assertSame(300, $appointments['history'][4]['value']);
                    $this->assertSame(425, $appointments['history'][5]['value']);

                    $doctors = $rows['doctors'];
                    $this->assertTrue($doctors['is_gauge']);
                    $this->assertTrue($doctors['exhausted']);
                    $this->assertSame(100, $doctors['percent']);
                    $this->assertSame([['period' => 'current', 'value' => 10]], $doctors['history']);

                    $this->assertFalse($rows['prescriptions']['capped']);
                    $this->assertNull($rows['prescriptions']['limit']);

                    return true;
                }));
    }

    public function test_the_csv_export_carries_the_board_under_its_filter_and_is_audited(): void
    {
        $me = $this->actingAsSuper();
        $a = $this->tenant('a');
        $b = $this->tenant('b');
        $this->setLimit($a, PlanFeatureKey::SmsCreditsMonthly, 100);
        $this->setUsage($a, UsageMetric::SmsCredits, 10);
        $this->setLimit($b, PlanFeatureKey::SmsCreditsMonthly, 100);
        $this->setUsage($b, UsageMetric::SmsCredits, 100);

        $response = $this->get('/usage/export?metric=sms_credits&filter=over');
        $response->assertOk();
        $this->assertStringStartsWith('text/csv', (string) $response->headers->get('content-type'));

        $lines = array_values(array_filter(explode("\n", trim($response->streamedContent()))));
        $this->assertStringContainsString('clinic,slug,status,plan,metric,value,limit,percent,state', $lines[0]);
        $this->assertCount(2, $lines);
        $this->assertStringContainsString($b->slug.',', $lines[1]);
        $this->assertStringContainsString(',sms_credits,100,100,100,over', $lines[1]);

        $log = AuditLogCentral::query()->where('action', CentralAuditAction::Export->value)->where('super_admin_id', $me->id)->latest('id')->firstOrFail();
        $this->assertEqualsCanonicalizing(['what' => 'usage', 'metric' => 'sms_credits', 'filter' => 'over', 'rows' => 1], $log->after);
    }
}
