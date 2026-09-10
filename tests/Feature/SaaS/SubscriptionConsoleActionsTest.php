<?php

declare(strict_types=1);

namespace Tests\Feature\SaaS;

use App\Domain\Audit\Enums\CentralAuditAction;
use App\Domain\SaaS\Enums\SubscriptionInvoiceStatus;
use App\Domain\SaaS\Enums\SubscriptionStatus;
use App\Domain\SaaS\Enums\TenantStatus;
use App\Models\Central\AuditLogCentral;
use App\Models\Central\Plan;
use App\Models\Central\Subscription;
use App\Models\Central\SubscriptionInvoice;
use App\Models\Central\Tenant;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/**
 * The subscription desk's buttons produce EXACTLY the state the commands and the tenant-page buttons produce —
 * because they call the same Actions — and each one lands on the audit trail under the operator's name.
 */
final class SubscriptionConsoleActionsTest extends TestCase
{
    public function test_the_subscription_list_renders_filters_and_sorts(): void
    {
        $this->actingAsSuper();
        $a = $this->tenant('a');
        $pro = Plan::query()->where('code', 'pro')->firstOrFail();
        Subscription::query()->whereKey($a->current_subscription_id)->update(['status' => 'active', 'plan_id' => $pro->id, 'billing_cycle' => 'yearly', 'price_paisa' => $pro->price_yearly_paisa]);
        $a->forceFill(['status' => TenantStatus::Active])->save();

        $this->get(route('super.billing.subscriptions.index', absolute: false).'?q=test-a')->assertOk()
            ->assertInertia(fn ($page) => $page->component('Super/Billing/Subscriptions')
                ->where('subscriptions.0.tenant.slug', 'test-a')
                ->where('subscriptions.0.plan_code', 'pro')
                ->where('subscriptions.0.billing_cycle', 'yearly')
                ->where('subscriptions.0.status', 'active')
                ->where('subscriptions.0.is_current', true)
                ->where('subscriptions.0.arrears_paisa', 0)
                ->has('status_counts.active')
                ->has('plans.0.code')
                ->where('filters.sort', 'renewal'));

        $this->get(route('super.billing.subscriptions.index', absolute: false).'?status=active&plan=pro&cycle=yearly')->assertOk()
            ->assertInertia(fn ($page) => $page->where('subscriptions.0.tenant.slug', 'test-a'));
        $this->get(route('super.billing.subscriptions.index', absolute: false).'?status=active&plan=pro&cycle=monthly')->assertOk()
            ->assertInertia(fn ($page) => $page->where('subscriptions', []));
        $this->get(route('super.billing.subscriptions.index', absolute: false).'?sort=arrears')->assertOk();
        $this->get(route('super.billing.subscriptions.index', absolute: false).'?sort=bogus')->assertSessionHasErrors('sort');
    }

    public function test_ending_a_trial_from_the_console_is_the_same_transition_the_renewal_sweep_makes(): void
    {
        $this->actingAsSuper();
        $tenant = $this->tenant('a');
        $subscription = Subscription::query()->findOrFail($tenant->current_subscription_id);
        $subscription->forceFill(['status' => SubscriptionStatus::Trialing, 'price_paisa' => 400000, 'trial_ends_at' => CarbonImmutable::now()->addDays(3)])->save();
        $tenant->forceFill(['status' => TenantStatus::Trial])->save();

        $this->post(route('super.billing.subscriptions.end-trial', ['tenant' => $tenant->public_id], false))->assertRedirect()->assertSessionHas('flash.success');

        $this->assertSame(SubscriptionStatus::PastDue, $subscription->refresh()->status, 'a paid trial ends into past_due with the first invoice');
        $this->assertSame(TenantStatus::PastDue, $tenant->refresh()->status);
        $invoice = SubscriptionInvoice::query()->where('tenant_id', $tenant->id)->firstOrFail();
        $this->assertSame(400000, $invoice->total_paisa);
        $this->assertSame(SubscriptionInvoiceStatus::Overdue, $invoice->status);
        $this->assertNotNull($subscription->getAttribute('grace_until'));

        // Not on a trial any more: the button is refused, nothing else happens.
        $this->post(route('super.billing.subscriptions.end-trial', ['tenant' => $tenant->public_id], false))->assertSessionHasErrors('domain');
        $this->assertSame(1, SubscriptionInvoice::query()->where('tenant_id', $tenant->id)->count());
    }

    public function test_suspend_reactivate_and_cancel_from_the_desk_match_the_lifecycle_actions_and_are_audited(): void
    {
        $admin = $this->actingAsSuper();
        $tenant = $this->tenant('a');
        $subscription = Subscription::query()->findOrFail($tenant->current_subscription_id);
        $subscription->forceFill(['status' => SubscriptionStatus::Active, 'current_period_end' => CarbonImmutable::now()->addDays(10)])->save();
        $tenant->forceFill(['status' => TenantStatus::Active])->save();

        $this->post(route('super.billing.subscriptions.suspend', ['tenant' => $tenant->public_id], false), [])->assertSessionHasErrors('reason');
        $this->post(route('super.billing.subscriptions.suspend', ['tenant' => $tenant->public_id], false), ['reason' => 'chargeback dispute'])->assertRedirect();
        $this->assertSame(TenantStatus::Suspended, $tenant->refresh()->status);
        $this->assertSame('chargeback dispute', $tenant->suspension_reason);
        $this->assertSame(SubscriptionStatus::Suspended, $subscription->refresh()->status);

        // Reactivating with arrears needs `force`; the desk sends what the operator ticked.
        SubscriptionInvoice::query()->create([
            'tenant_id' => $tenant->id, 'subscription_id' => $subscription->id, 'number' => 'SI-ARR-'.$tenant->id,
            'status' => SubscriptionInvoiceStatus::Overdue, 'subtotal_paisa' => 100, 'total_paisa' => 100, 'paid_paisa' => 0,
            'line_items' => [], 'issued_at' => CarbonImmutable::now()->subDays(20), 'due_at' => CarbonImmutable::now()->subDays(13), 'dunning_step' => 3,
        ]);
        $this->post(route('super.billing.subscriptions.reactivate', ['tenant' => $tenant->public_id], false), ['reason' => 'goodwill'])->assertRedirect();
        $this->assertSame(TenantStatus::Suspended, $tenant->refresh()->status, 'arrears block a plain reactivation');
        $this->post(route('super.billing.subscriptions.reactivate', ['tenant' => $tenant->public_id], false), ['reason' => 'goodwill', 'force' => true])->assertRedirect();
        $this->assertSame(TenantStatus::Active, $tenant->refresh()->status);
        $this->assertSame(SubscriptionStatus::Active, $subscription->refresh()->status);

        // Cancel at period end keeps the clinic running; cancel now takes it off the internet.
        $this->post(route('super.billing.subscriptions.cancel', ['tenant' => $tenant->public_id], false), ['reason' => 'moving away'])->assertRedirect();
        $this->assertTrue((bool) $subscription->refresh()->getAttribute('cancel_at_period_end'));
        $this->assertSame(TenantStatus::Active, $tenant->refresh()->status);
        $this->post(route('super.billing.subscriptions.cancel', ['tenant' => $tenant->public_id], false), ['reason' => 'closed down', 'immediately' => true])->assertRedirect();
        $this->assertSame(SubscriptionStatus::Cancelled, $subscription->refresh()->status);
        $this->assertSame(TenantStatus::Cancelled, $tenant->refresh()->status);

        foreach ([CentralAuditAction::Suspend, CentralAuditAction::Reactivate, CentralAuditAction::Delete] as $action) {
            $this->assertTrue(AuditLogCentral::query()->where('tenant_id', $tenant->id)->where('action', $action->value)->where('super_admin_id', $admin->id)->exists(), $action->value);
        }
    }

    public function test_changing_the_plan_from_the_desk_moves_entitlements_now_and_price_at_the_next_period(): void
    {
        $admin = $this->actingAsSuper();
        $tenant = $this->tenant('a');
        $subscription = Subscription::query()->findOrFail($tenant->current_subscription_id);
        $pro = Plan::query()->where('code', 'pro')->firstOrFail();

        $this->post(route('super.billing.subscriptions.plan', ['tenant' => $tenant->public_id], false), ['plan' => 'nope'])->assertSessionHasErrors('plan');
        $this->post(route('super.billing.subscriptions.plan', ['tenant' => $tenant->public_id], false), ['plan' => 'pro', 'billing_cycle' => 'yearly'])->assertRedirect();

        $subscription->refresh();
        $this->assertSame($pro->id, $subscription->plan_id);
        $this->assertSame($pro->price_yearly_paisa, $subscription->price_paisa, 'the new rate is locked for the NEXT period');
        $this->assertSame(0, SubscriptionInvoice::query()->where('tenant_id', $tenant->id)->count(), 'no proration invoice is invented');
        $this->assertTrue(AuditLogCentral::query()->where('tenant_id', $tenant->id)->where('action', CentralAuditAction::PlanChange->value)->where('super_admin_id', $admin->id)->exists());
    }

    public function test_a_row_for_a_clinic_of_another_status_is_refused_when_it_makes_no_sense(): void
    {
        $this->actingAsSuper();
        $tenant = Tenant::factory()->cancelled()->create();

        // No current subscription at all: end-trial and plan change refuse; the suspend/cancel paths are no-ops.
        $this->post(route('super.billing.subscriptions.end-trial', ['tenant' => $tenant->public_id], false))->assertSessionHasErrors('domain');
        $this->post(route('super.billing.subscriptions.cancel', ['tenant' => $tenant->public_id], false), ['reason' => 'x'])->assertRedirect();
        $this->assertSame(TenantStatus::Cancelled, $tenant->refresh()->status);
    }
}
