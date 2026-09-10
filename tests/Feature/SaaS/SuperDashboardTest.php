<?php

declare(strict_types=1);

namespace Tests\Feature\SaaS;

use App\Domain\SaaS\Enums\PlanFeatureKey;
use App\Domain\SaaS\Enums\SubscriptionInvoiceStatus;
use App\Domain\SaaS\Enums\SubscriptionStatus;
use App\Domain\SaaS\Enums\SuperTwoFactorPolicy;
use App\Domain\SaaS\Enums\TenantStatus;
use App\Domain\SaaS\Enums\UsageMetric;
use App\Domain\SaaS\Queries\PlatformTrend;
use App\Models\Central\Subscription;
use App\Models\Central\SubscriptionInvoice;
use App\Models\Central\SuperAdmin;
use App\Models\Central\Tenant;
use App\Models\Tenant\Appointment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\SaaS\Concerns\ControlsPlanLimits;
use Tests\Feature\SaaS\Concerns\ControlsPlatformSettings;
use Tests\TestCase;

/**
 * The operator's morning screen: every tile and every attention item is a real count. The fixtures here put a
 * known number behind each one and assert the screen shows exactly that.
 */
final class SuperDashboardTest extends TestCase
{
    use ControlsPlanLimits;
    use ControlsPlatformSettings;

    protected function setUp(): void
    {
        parent::setUp();
        app(PlatformTrend::class)->forget();
    }

    public function test_the_dashboard_renders_tiles_attention_items_and_the_thirty_day_trend(): void
    {
        $this->actingAsSuper();
        $a = $this->tenant('a');
        $b = $this->tenant('b');
        $now = CarbonImmutable::now();

        // A trial ending in three days, and one past-due clinic with an overdue invoice worth 1,500 taka (the
        // money comes from Billing's PlatformRevenueSummary: a past-due CURRENT subscription, a due date that passed).
        $a->forceFill(['status' => TenantStatus::Trial->value, 'trial_ends_at' => $now->addDays(3), 'last_backup_at' => $now->subDays(5)])->save();
        $b->forceFill(['status' => TenantStatus::PastDue->value, 'last_backup_at' => null])->save();
        Subscription::query()->whereKey($b->current_subscription_id)->update(['status' => SubscriptionStatus::PastDue->value]);
        SubscriptionInvoice::factory()->create(['tenant_id' => $b->id, 'status' => SubscriptionInvoiceStatus::Overdue->value, 'total_paisa' => 150000, 'paid_paisa' => 0, 'issued_at' => $now->subDays(10), 'due_at' => $now->subDays(3)]);

        // SMS nearly exhausted on A (80 of 100), and over the doctor cap on B (5 of 4).
        $this->setLimit($a, PlanFeatureKey::SmsCreditsMonthly, 100);
        $this->setUsage($a, UsageMetric::SmsCredits, 80);
        $this->setLimit($b, PlanFeatureKey::Doctors, 4);
        $this->setUsage($b, UsageMetric::Doctors, 5);

        // An operator with no second factor is counted only while the policy asks for one.
        $this->setSuperTwoFactorPolicy(SuperTwoFactorPolicy::Required);
        SuperAdmin::factory()->create();

        DB::table('public.failed_jobs')->insert(['uuid' => (string) Str::uuid(), 'connection' => 'redis', 'queue' => 'default', 'payload' => '{}', 'exception' => 'boom', 'failed_at' => $now]);

        $this->get('/')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->component('Super/Dashboard')
                ->has('totals.tenants')
                ->where('kpis.trials_ending_7d', 1)
                ->where('kpis.past_due.tenants', 1)
                ->where('kpis.past_due.invoices', 1)
                ->where('kpis.past_due.paisa', 150000)
                ->where('kpis.revenue.outstanding_paisa', 150000)
                ->where('kpis.revenue.outstanding_invoices', 1)
                ->has('kpis.revenue.mrr_paisa')
                ->has('kpis.revenue.collected_month_paisa')
                ->where('kpis.signups_month', fn ($n) => $n >= 2)
                ->where('kpis.sms_near_limit', 1)
                ->where('kpis.over_limit', 1)
                ->where('kpis.backups.never', 1)
                ->where('kpis.backups.stale', 1)
                ->where('kpis.backups.worst.slug', $b->slug)
                ->where('kpis.queue.failed', 1)
                ->has('kpis.queue.horizon_url')
                ->has('kpis.appointments_today')
                ->has('trend', PlatformTrend::DAYS)
                ->has('recent')
                ->has('plans')
                ->where('attention', function (Collection $items): bool {
                    $items = $items->keyBy('key');
                    $this->assertSame(1, $items['overdue_invoices']['count']);
                    $this->assertSame(150000, $items['overdue_invoices']['amount_paisa']);
                    $this->assertSame('super.tenants.index', $items['overdue_invoices']['route']);
                    $this->assertSame(['status' => 'past_due'], $items['overdue_invoices']['params']);
                    $this->assertSame(1, $items['failed_jobs']['count']);
                    $this->assertStringEndsWith('/failed', (string) $items['failed_jobs']['href']);
                    $this->assertSame(1, $items['trials_ending']['count']);
                    $this->assertSame(1, $items['sms_near_limit']['count']);
                    $this->assertSame(['metric' => 'sms_credits', 'filter' => 'near'], $items['sms_near_limit']['params']);
                    $this->assertSame(1, $items['over_limit']['count']);
                    $this->assertSame(1, $items['backups_never']['count']);
                    $this->assertSame(1, $items['backups_stale']['count']);
                    $this->assertSame(1, $items['admins_without_2fa']['count']);
                    $this->assertSame('super.admins.index', $items['admins_without_2fa']['route']);
                    // Zero counts are dropped, not shown as zeros.
                    $this->assertArrayNotHasKey('promotions_pending', $items->all());
                    $this->assertTrue($items->every(fn (array $i): bool => $i['count'] > 0));

                    return true;
                }));

        $this->assertSame(0, Tenant::query()->where('id', $b->id)->whereNotNull('last_backup_at')->count());

        // Under `disabled` nobody is asked for a factor, so the item disappears rather than nagging.
        $this->setSuperTwoFactorPolicy(SuperTwoFactorPolicy::Disabled);
        $this->get('/')->assertOk()
            ->assertInertia(fn (AssertableInertia $page) => $page->where('attention', fn (Collection $items): bool => ! $items->contains('key', 'admins_without_2fa')));
    }

    public function test_appointments_today_and_the_trend_are_counted_across_every_clinic_schema(): void
    {
        $this->actingAsSuper();
        $today = CarbonImmutable::now('Asia/Dhaka')->toDateString();
        $yesterday = CarbonImmutable::now('Asia/Dhaka')->subDay()->toDateString();

        $this->asTenant('a');
        Appointment::factory()->count(2)->create(['scheduled_date' => $today, 'status' => 'pending']);
        Appointment::factory()->create(['scheduled_date' => $today, 'status' => 'cancelled']);   // not counted
        Appointment::factory()->create(['scheduled_date' => $yesterday, 'status' => 'pending']);

        $this->asTenant('b');
        Appointment::factory()->create(['scheduled_date' => $today, 'status' => 'pending']);

        $this->asCentral();
        app(PlatformTrend::class)->forget();
        $trend = app(PlatformTrend::class)->last30Days();

        $this->assertSame(3, app(PlatformTrend::class)->appointmentsToday());
        $byDay = collect($trend)->keyBy('day');
        $this->assertSame(3, $byDay[$today]['appointments']);
        $this->assertSame(1, $byDay[$yesterday]['appointments']);
        $this->assertSame(PlatformTrend::DAYS, count($trend));
        $this->assertSame($today, $trend[PlatformTrend::DAYS - 1]['day']);

        // Cached: another appointment does not move the number until the strip is forgotten.
        $this->asTenant('a');
        Appointment::factory()->create(['scheduled_date' => $today, 'status' => 'pending']);
        $this->asCentral();
        $this->assertSame(3, app(PlatformTrend::class)->appointmentsToday());
        app(PlatformTrend::class)->forget();
        $this->assertSame(4, app(PlatformTrend::class)->appointmentsToday());
        $this->assertTrue(Cache::has('bp:super:trend:'.$today));
    }
}
