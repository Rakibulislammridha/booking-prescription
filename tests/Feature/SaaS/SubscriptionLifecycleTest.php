<?php

declare(strict_types=1);

namespace Tests\Feature\SaaS;

use App\Domain\Audit\Enums\CentralAuditAction;
use App\Domain\SaaS\Actions\Subscriptions\CancelSubscription;
use App\Domain\SaaS\Actions\Subscriptions\EndTrial;
use App\Domain\SaaS\Actions\Subscriptions\RenewSubscription;
use App\Domain\SaaS\Enums\SubscriptionInvoiceStatus;
use App\Domain\SaaS\Enums\SubscriptionStatus;
use App\Domain\SaaS\Enums\TenantStatus;
use App\Domain\SaaS\Events\DunningNoticeDue;
use App\Domain\SaaS\Events\TenantAutoSuspended;
use App\Domain\SaaS\Support\DunningSchedule;
use App\Models\Central\AuditLogCentral;
use App\Models\Central\Plan;
use App\Models\Central\Subscription;
use App\Models\Central\SubscriptionInvoice;
use App\Models\Central\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * The subscription state machine end to end: trial → active → past_due → suspended → back, plus what each status
 * does to a real tenant request (which is `EnsureTenantIsActive`'s existing contract — this suite pins it so a
 * change to the SaaS module cannot quietly alter what a clinic sees).
 */
final class SubscriptionLifecycleTest extends TestCase
{
    public function test_a_free_trial_converts_straight_to_active_with_no_invoice(): void
    {
        [$tenant, $subscription] = $this->tenantOnPlan('starter', priceMonthly: 0, trialDays: 14);
        $this->travelTo($subscription->getAttribute('trial_ends_at')?->addMinute());

        app(EndTrial::class)->handle($subscription->refresh());

        $this->assertSame(SubscriptionStatus::Active, $subscription->refresh()->status);
        $this->assertSame(TenantStatus::Active, $tenant->refresh()->status);
        $this->assertSame(0, SubscriptionInvoice::query()->where('tenant_id', $tenant->id)->count());
        $this->travelBack();
    }

    public function test_a_paid_trial_ends_into_past_due_with_the_first_invoice_and_a_grace_deadline(): void
    {
        [$tenant, $subscription] = $this->tenantOnPlan('pro-test', priceMonthly: 400000, trialDays: 14);
        Event::fake([DunningNoticeDue::class]);
        $end = $subscription->getAttribute('trial_ends_at')?->addMinute();
        $this->travelTo($end);

        app(EndTrial::class)->handle($subscription->refresh());

        $subscription->refresh();
        $this->assertSame(SubscriptionStatus::PastDue, $subscription->status);
        $this->assertSame(TenantStatus::PastDue, $tenant->refresh()->status);

        $invoice = SubscriptionInvoice::query()->where('tenant_id', $tenant->id)->firstOrFail();
        $this->assertSame(SubscriptionInvoiceStatus::Overdue, $invoice->status, 'the first invoice is due immediately, so the ladder starts at once');
        $this->assertSame(400000, $invoice->total_paisa);
        $this->assertInstanceOf(CarbonImmutable::class, $subscription->getAttribute('grace_until'));

        Event::assertDispatched(DunningNoticeDue::class, fn (DunningNoticeDue $e) => $e->step === 1 && $e->isFinalNotice === false);
        $this->travelBack();
    }

    public function test_an_active_subscription_renews_into_the_next_period_and_stays_active_until_the_invoice_is_late(): void
    {
        [$tenant, $subscription] = $this->tenantOnPlan('pro-test', priceMonthly: 400000, trialDays: 0);
        $subscription->forceFill([
            'status' => SubscriptionStatus::Active,
            'current_period_start' => CarbonImmutable::now()->subMonth(),
            'current_period_end' => CarbonImmutable::now()->subMinute(),
        ])->save();

        app(RenewSubscription::class)->handle($subscription);

        $subscription->refresh();
        $this->assertSame(SubscriptionStatus::Active, $subscription->status, 'an outstanding invoice is not the same as an overdue one');
        $this->assertSame(TenantStatus::Active, $tenant->refresh()->status);
        $this->assertTrue($subscription->current_period_end->isFuture());

        $invoice = SubscriptionInvoice::query()->where('tenant_id', $tenant->id)->firstOrFail();
        $this->assertSame(SubscriptionInvoiceStatus::Issued, $invoice->status);
        $this->assertTrue($invoice->getAttribute('due_at')->isFuture());
    }

    public function test_renewal_is_idempotent_and_never_bills_a_period_twice(): void
    {
        [$tenant, $subscription] = $this->tenantOnPlan('pro-test', priceMonthly: 400000, trialDays: 0);
        $subscription->forceFill([
            'status' => SubscriptionStatus::Active,
            'current_period_start' => CarbonImmutable::now()->subMonth(),
            'current_period_end' => CarbonImmutable::now()->subMinute(),
        ])->save();

        app(RenewSubscription::class)->handle($subscription);
        app(RenewSubscription::class)->handle($subscription->refresh());

        $this->assertSame(1, SubscriptionInvoice::query()->where('tenant_id', $tenant->id)->count());
    }

    public function test_the_dunning_ladder_emits_each_step_once_and_auto_suspends_when_the_grace_expires(): void
    {
        Event::fake([DunningNoticeDue::class, TenantAutoSuspended::class]);
        [$tenant, $subscription] = $this->tenantOnPlan('pro-test', priceMonthly: 400000, trialDays: 0);
        $dueAt = CarbonImmutable::now();
        $invoice = $this->issuedInvoice($tenant, $subscription, $dueAt);

        foreach (DunningSchedule::STEPS as $index => $days) {
            $this->travelTo($dueAt->addDays($days)->addMinute());
            $this->artisan('saas:dun')->assertSuccessful();
            $this->artisan('saas:dun')->assertSuccessful();          // a second sweep must add nothing

            $this->assertSame($index + 1, (int) $invoice->refresh()->getAttribute('dunning_step'));
            Event::assertDispatchedTimes(DunningNoticeDue::class, $index + 1);
        }

        $this->assertSame(SubscriptionStatus::PastDue, $subscription->refresh()->status);
        $this->assertSame(TenantStatus::PastDue, $tenant->refresh()->status, 'a past_due clinic keeps working');
        Event::assertNotDispatched(TenantAutoSuspended::class);

        // Grace expires.
        $this->travelTo(DunningSchedule::graceDeadline($dueAt)->addMinute());
        $this->artisan('saas:dun')->assertSuccessful();

        $this->assertSame(TenantStatus::Suspended, $tenant->refresh()->status);
        $this->assertSame(SubscriptionStatus::Suspended, $subscription->refresh()->status);
        $this->assertNotNull($tenant->suspended_at);
        Event::assertDispatchedTimes(TenantAutoSuspended::class, 1);

        // Idempotent: a second sweep does not re-suspend or re-notify.
        $this->artisan('saas:dun')->assertSuccessful();
        Event::assertDispatchedTimes(TenantAutoSuspended::class, 1);

        $this->assertTrue(AuditLogCentral::query()->where('tenant_id', $tenant->id)->where('action', CentralAuditAction::Suspend->value)->exists());
        $this->travelBack();
    }

    public function test_a_final_notice_is_flagged_as_final_and_names_the_suspension_date(): void
    {
        Event::fake([DunningNoticeDue::class]);
        [$tenant, $subscription] = $this->tenantOnPlan('pro-test', priceMonthly: 400000, trialDays: 0);
        $dueAt = CarbonImmutable::now();
        $this->issuedInvoice($tenant, $subscription, $dueAt);

        $this->travelTo($dueAt->addDays(DunningSchedule::STEPS[count(DunningSchedule::STEPS) - 1])->addMinute());
        $this->artisan('saas:dun')->assertSuccessful();

        Event::assertDispatched(DunningNoticeDue::class, function (DunningNoticeDue $e) use ($dueAt): bool {
            return $e->isFinalNotice === true
                && $e->step === DunningSchedule::steps()
                && CarbonImmutable::parse($e->suspendsOn)->toIso8601String() === DunningSchedule::graceDeadline($dueAt)->toIso8601String();
        });
        $this->travelBack();
    }

    public function test_cancelling_at_period_end_expires_the_subscription_at_the_next_renewal(): void
    {
        [$tenant, $subscription] = $this->tenantOnPlan('pro-test', priceMonthly: 400000, trialDays: 0);
        $subscription->forceFill(['status' => SubscriptionStatus::Active, 'current_period_end' => CarbonImmutable::now()->addDay()])->save();

        app(CancelSubscription::class)->handle($tenant, 'moving to paper', immediately: false);

        $this->assertTrue((bool) $subscription->refresh()->getAttribute('cancel_at_period_end'));
        $this->assertSame(TenantStatus::Active, $tenant->refresh()->status, 'the clinic keeps what it paid for until the period ends');

        $subscription->forceFill(['current_period_start' => CarbonImmutable::now()->subMonth(), 'current_period_end' => CarbonImmutable::now()->subMinute()])->save();
        app(RenewSubscription::class)->handle($subscription->refresh());

        $this->assertSame(SubscriptionStatus::Expired, $subscription->refresh()->status);
        $this->assertSame(TenantStatus::Suspended, $tenant->refresh()->status, 'expired is suspended, not 404: one payment brings it back');
        $this->assertSame(0, SubscriptionInvoice::query()->where('tenant_id', $tenant->id)->count());
    }

    public function test_cancelling_immediately_takes_the_clinic_off_the_internet_without_deleting_anything(): void
    {
        [$tenant, $subscription] = $this->tenantOnPlan('pro-test', priceMonthly: 400000, trialDays: 0);

        app(CancelSubscription::class)->handle($tenant, 'closed down', immediately: true);

        $this->assertSame(SubscriptionStatus::Cancelled, $subscription->refresh()->status);
        $this->assertSame(TenantStatus::Cancelled, $tenant->refresh()->status);
        $this->assertNotSame('', $tenant->schema_name);
        $this->assertNotNull(Tenant::query()->find($tenant->id), 'cancellation never deletes the tenant row');
    }

    /**
     * The other half of the state machine: what each status does to an actual HTTP request. This is
     * `EnsureTenantIsActive`'s contract (ARCHITECTURE §4) and it is asserted here because the SaaS module is what
     * moves tenants between these statuses.
     */
    public function test_each_tenant_status_does_exactly_what_the_middleware_promises(): void
    {
        $tenant = $this->tenant('a');
        $this->asTenant('a');

        foreach ([TenantStatus::Trial, TenantStatus::Active] as $status) {
            $tenant->forceFill(['status' => $status])->save();
            $this->get('/')->assertOk()
                ->assertInertia(fn ($page) => $page->component('Home/Index')->where('flash.warning', null));
        }

        $tenant->forceFill(['status' => TenantStatus::PastDue])->save();
        $this->get('/')->assertOk()
            ->assertInertia(fn ($page) => $page->component('Home/Index')->where('flash.warning', __('tenancy.past_due_banner')));

        $tenant->forceFill(['status' => TenantStatus::Suspended, 'suspended_at' => CarbonImmutable::now()])->save();
        $this->get('/')->assertStatus(402)->assertInertia(fn ($page) => $page->component('Suspended'));
        $this->getJson('/api/ping')->assertStatus(402)->assertJsonPath('code', 'tenancy.suspended');

        $tenant->forceFill(['status' => TenantStatus::Cancelled])->save();
        $this->get('/')->assertNotFound();
        $this->get('/panel')->assertNotFound();
    }

    /** @return array{0: Tenant, 1: Subscription} */
    private function tenantOnPlan(string $planCode, int $priceMonthly, int $trialDays): array
    {
        $plan = Plan::query()->firstOrCreate(
            ['code' => $planCode],
            ['name' => ucfirst($planCode), 'price_monthly_paisa' => $priceMonthly, 'price_yearly_paisa' => $priceMonthly * 10, 'trial_days' => $trialDays, 'is_public' => true, 'is_addon' => false, 'sort_order' => 90],
        );
        $plan->forceFill(['price_monthly_paisa' => $priceMonthly, 'trial_days' => $trialDays])->save();

        $tenant = $this->tenant('a');
        $now = CarbonImmutable::now();

        /** @var Subscription $subscription */
        $subscription = Subscription::query()->findOrFail($tenant->current_subscription_id);
        $subscription->forceFill([
            'plan_id' => $plan->id,
            'status' => $trialDays > 0 ? SubscriptionStatus::Trialing : SubscriptionStatus::Active,
            'price_paisa' => $priceMonthly,
            'current_period_start' => $now,
            'current_period_end' => $now->addDays(max(1, $trialDays)),
            'trial_ends_at' => $trialDays > 0 ? $now->addDays($trialDays) : null,
            'grace_until' => null,
        ])->save();

        $tenant->forceFill([
            'status' => $trialDays > 0 ? TenantStatus::Trial : TenantStatus::Active,
            'trial_ends_at' => $trialDays > 0 ? $now->addDays($trialDays) : null,
            'suspended_at' => null,
            'suspension_reason' => null,
        ])->save();

        return [$tenant, $subscription->refresh()];
    }

    private function issuedInvoice(Tenant $tenant, Subscription $subscription, CarbonImmutable $dueAt): SubscriptionInvoice
    {
        return SubscriptionInvoice::query()->create([
            'tenant_id' => $tenant->id,
            'subscription_id' => $subscription->id,
            'number' => 'SI-TEST-'.$subscription->id,
            'status' => SubscriptionInvoiceStatus::Issued,
            'period_start' => $dueAt->toDateString(),
            'period_end' => $dueAt->addMonth()->toDateString(),
            'subtotal_paisa' => 400000,
            'total_paisa' => 400000,
            'paid_paisa' => 0,
            'line_items' => [['description' => 'Pro', 'quantity' => 1, 'unit_paisa' => 400000, 'total_paisa' => 400000, 'feature_key' => null]],
            'issued_at' => $dueAt->subDays(7),
            'due_at' => $dueAt,
            'dunning_step' => 0,
        ]);
    }
}
