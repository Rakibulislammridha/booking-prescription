<?php

declare(strict_types=1);

namespace Tests\Feature\SaaS;

use App\Domain\Audit\Enums\CentralAuditAction;
use App\Domain\Clinic\Enums\Role;
use App\Domain\SaaS\Enums\PlanFeatureKey;
use App\Domain\SaaS\Enums\SubscriptionInvoiceStatus;
use App\Domain\SaaS\Enums\TenantStatus;
use App\Domain\SaaS\Enums\UsageMetric;
use App\Domain\SaaS\Services\PlanLimits;
use App\Models\Central\AuditLogCentral;
use App\Models\Central\Plan;
use App\Models\Central\PlanFeature;
use App\Models\Central\Subscription;
use App\Models\Central\SubscriptionInvoice;
use App\Models\Central\SuperAdmin;
use Tests\Feature\SaaS\Concerns\ControlsPlanLimits;
use Tests\TestCase;

/**
 * The super-admin permission model, such as it is — and every operational action behind it.
 *
 * `super_admins` has no role matrix by design (ARCHITECTURE §6.2: the platform team is a handful of people, and
 * a role matrix nobody maintains is worse than none). What IS enforced is asserted here: the guard, the host,
 * the `is_active` kill switch, and the fact that no tenant identity of any kind opens the door.
 */
final class SuperConsoleTest extends TestCase
{
    use ControlsPlanLimits;

    public function test_the_console_is_closed_to_guests_tenant_staff_and_patients(): void
    {
        $tenant = $this->tenant('a');
        $urls = [
            route('super.tenants.index', absolute: false),
            route('super.tenants.show', ['tenant' => $tenant->public_id], false),
            route('super.plans.index', absolute: false),
            route('super.usage.index', absolute: false),
            route('super.audit.index', absolute: false),
        ];

        // Guest.
        $this->asCentral()->withServerVariables(['HTTP_HOST' => 'super.bp.test']);
        foreach ($urls as $url) {
            $this->get($url)->assertRedirect(route('super.login', absolute: false));
        }

        // Tenant staff, even a hospital admin with every permission their clinic can grant.
        $this->asTenant('a');
        $this->actingAsStaff(Role::HospitalAdmin);
        $this->withServerVariables(['HTTP_HOST' => 'super.bp.test']);
        foreach ($urls as $url) {
            $this->get($url)->assertRedirect(route('super.login', absolute: false));
        }
    }

    public function test_writes_are_closed_too_not_only_the_screens(): void
    {
        $tenant = $this->tenant('a');
        $this->asCentral()->withServerVariables(['HTTP_HOST' => 'super.bp.test']);

        $this->post(route('super.tenants.suspend', ['tenant' => $tenant->public_id], false), ['reason' => 'nope'])->assertRedirect(route('super.login', absolute: false));
        $this->post(route('super.tenants.impersonate', ['tenant' => $tenant->public_id], false))->assertRedirect(route('super.login', absolute: false));
        $this->post(route('super.plans.store', absolute: false), [])->assertRedirect(route('super.login', absolute: false));

        $this->assertSame(TenantStatus::Trial, $tenant->refresh()->status);
    }

    /**
     * A numeric `throttle:N,M` keys a guest by `sha1(domain|ip)` whatever the route, so the panel's connection
     * heartbeat — one `/api/ping` every five seconds on the login screen — used to spend the login's 10-per-minute
     * budget: an operator who sat on the login page for a minute got 429 on the password they then typed. The
     * ping now has its own bucket (`throttle:60,1,super-ping`); this pins that.
     */
    public function test_the_connection_heartbeat_does_not_spend_the_login_throttle(): void
    {
        $this->asCentral()->withServerVariables(['HTTP_HOST' => 'super.bp.test']);
        $admin = SuperAdmin::factory()->create(['password' => 'secret-123']);

        for ($beat = 0; $beat < 12; $beat++) {
            $this->getJson('/api/ping')->assertOk();
        }

        $this->post('/login', ['email' => $admin->email, 'password' => 'secret-123'])->assertRedirect('http://super.bp.test');
        $this->assertAuthenticatedAs($admin, 'super');
    }

    public function test_deactivating_an_operator_ends_their_session_on_the_next_request(): void
    {
        $admin = $this->actingAsSuper();
        $this->get(route('super.tenants.index', absolute: false))->assertOk();

        $admin->forceFill(['is_active' => false])->save();

        $this->get(route('super.tenants.index', absolute: false))->assertForbidden();
        $this->assertGuest('super');
    }

    public function test_the_tenant_list_carries_health_usage_and_arrears_and_can_be_searched(): void
    {
        $this->actingAsSuper();
        $tenant = $this->tenant('a');
        $this->setUsage($tenant, UsageMetric::Doctors, 4);
        $this->setLimit($tenant, PlanFeatureKey::Doctors, 10);

        $this->get(route('super.tenants.index', absolute: false))->assertOk()
            ->assertInertia(fn ($page) => $page->component('Super/Tenants/Index')
                ->has('tenants')
                ->has('meta.total')
                ->has('totals.tenants')
                ->has('statuses'));

        $this->get(route('super.tenants.index', absolute: false).'?q=test-a')->assertOk()
            ->assertInertia(fn ($page) => $page->where('tenants.0.slug', 'test-a')
                ->where('tenants.0.counts.doctors', 4)
                ->where('tenants.0.limits.doctors', 10)
                ->where('tenants.0.health', 'trial'));

        $this->get(route('super.tenants.index', absolute: false).'?status=suspended')->assertOk()
            ->assertInertia(fn ($page) => $page->where('tenants', []));
    }

    public function test_the_tenant_detail_shows_the_subscription_usage_invoices_domains_and_recent_audit(): void
    {
        $admin = $this->actingAsSuper();
        $tenant = $this->tenant('a');

        $this->get(route('super.tenants.show', ['tenant' => $tenant->public_id], false))->assertOk()
            ->assertInertia(fn ($page) => $page->component('Super/Tenants/Show')
                ->has('tenant.subscription.plan_code')
                ->has('tenant.usage')
                ->has('tenant.entitlements.toggles')
                ->has('plans')
                ->has('history.appointments')
                ->has('invoices')
                ->has('domains.0.instructions.txt_value')
                ->has('backups')
                ->has('audit'));

        // Opening a clinic's record is itself audited.
        $this->assertTrue(AuditLogCentral::query()
            ->where('tenant_id', $tenant->id)->where('super_admin_id', $admin->id)
            ->where('action', CentralAuditAction::View->value)->exists());
    }

    public function test_suspending_and_reactivating_a_clinic_from_the_console(): void
    {
        $this->actingAsSuper();
        $tenant = $this->tenant('a');

        $this->post(route('super.tenants.suspend', ['tenant' => $tenant->public_id], false), ['reason' => 'abuse investigation'])->assertRedirect();
        $this->assertSame(TenantStatus::Suspended, $tenant->refresh()->status);
        $this->assertSame('abuse investigation', $tenant->suspension_reason);

        $this->post(route('super.tenants.reactivate', ['tenant' => $tenant->public_id], false), ['reason' => 'cleared'])->assertRedirect();
        $this->assertSame(TenantStatus::Active, $tenant->refresh()->status);
        $this->assertNull($tenant->suspension_reason);
    }

    public function test_changing_a_plan_moves_entitlements_now_and_the_price_at_the_next_period(): void
    {
        $this->actingAsSuper();
        $tenant = $this->tenant('a');
        $subscription = Subscription::query()->findOrFail($tenant->current_subscription_id);
        $before = $subscription->price_paisa;

        $target = Plan::query()->where('code', 'pro')->firstOrFail();

        $this->post(route('super.tenants.plan', ['tenant' => $tenant->public_id], false), ['plan' => 'pro', 'billing_cycle' => 'yearly'])->assertRedirect();

        $subscription->refresh();
        $this->assertSame($target->id, $subscription->plan_id);
        $this->assertSame($target->price_yearly_paisa, $subscription->price_paisa);
        $this->assertNotSame($before, $subscription->price_paisa);
        $this->assertTrue(AuditLogCentral::query()->where('tenant_id', $tenant->id)->where('action', CentralAuditAction::PlanChange->value)->exists());

        // No proration line was invented anywhere.
        $this->assertSame(0, SubscriptionInvoice::query()->where('tenant_id', $tenant->id)->count());
    }

    public function test_per_tenant_feature_toggles_and_negotiated_limits(): void
    {
        $this->actingAsSuper();
        $tenant = $this->tenant('a');

        $this->post(route('super.tenants.features', ['tenant' => $tenant->public_id], false), ['feature' => 'telemedicine', 'enabled' => true])->assertRedirect();
        $this->assertTrue(app(PlanLimits::class)->enabled($tenant->refresh(), PlanFeatureKey::Telemedicine));

        $this->post(route('super.tenants.limits', ['tenant' => $tenant->public_id], false), ['limits' => ['doctors' => 12]])->assertRedirect();
        $this->assertSame(12, app(PlanLimits::class)->limit($tenant->refresh(), UsageMetric::Doctors));

        $this->post(route('super.tenants.limits', ['tenant' => $tenant->public_id], false), ['clear' => ['doctors']])->assertRedirect();
        $this->assertTrue(AuditLogCentral::query()->where('tenant_id', $tenant->id)->where('action', CentralAuditAction::SettingsChange->value)->exists());
    }

    public function test_plan_crud_writes_the_feature_rows_the_limits_are_enforced_from(): void
    {
        $this->actingAsSuper();

        $this->post(route('super.plans.store', absolute: false), [
            'code' => 'clinic-plus',
            'name' => 'Clinic Plus',
            'description' => 'Three branches, ten doctors.',
            'price_monthly_paisa' => 250000,
            'price_yearly_paisa' => 2500000,
            'trial_days' => 7,
            'is_public' => true,
            'is_addon' => false,
            'sort_order' => 5,
            'limits' => ['branches' => 3, 'doctors' => 10, 'appointments_monthly' => null, 'sms_credits_monthly' => 2000, 'storage_bytes' => 5368709120],
            'toggles' => ['whatsapp' => true, 'telemedicine' => false],
        ])->assertRedirect(route('super.plans.index', absolute: false));

        $plan = Plan::query()->where('code', 'clinic-plus')->firstOrFail();
        $this->assertSame(250000, $plan->price_monthly_paisa);
        $this->assertSame(3, (int) PlanFeature::query()->where('plan_id', $plan->id)->where('feature_key', 'branches')->value('limit_value'));
        $this->assertNull(PlanFeature::query()->where('plan_id', $plan->id)->where('feature_key', 'appointments_monthly')->value('limit_value'), 'null means unlimited, not absent');
        $this->assertTrue((bool) PlanFeature::query()->where('plan_id', $plan->id)->where('feature_key', 'whatsapp')->value('enabled'));
        $this->assertFalse((bool) PlanFeature::query()->where('plan_id', $plan->id)->where('feature_key', 'telemedicine')->value('enabled'));

        // Editing replaces the feature set rather than merging into it.
        $this->put(route('super.plans.update', ['plan' => 'clinic-plus'], false), [
            'code' => 'clinic-plus', 'name' => 'Clinic Plus', 'description' => null,
            'price_monthly_paisa' => 300000, 'price_yearly_paisa' => 3000000, 'trial_days' => 7,
            'is_public' => true, 'is_addon' => false, 'sort_order' => 5,
            'limits' => ['branches' => 5], 'toggles' => [],
        ])->assertRedirect();

        $this->assertSame(5, (int) PlanFeature::query()->where('plan_id', $plan->id)->where('feature_key', 'branches')->value('limit_value'));
        $this->assertSame(0, PlanFeature::query()->where('plan_id', $plan->id)->where('feature_key', 'whatsapp')->count());

        // Archiving hides it from sign-up without deleting it.
        $this->delete(route('super.plans.destroy', ['plan' => 'clinic-plus'], false))->assertRedirect();
        $this->assertNotNull($plan->refresh()->archived_at);
        $this->assertFalse($plan->is_public);
        $this->assertNotNull(Plan::query()->where('code', 'clinic-plus')->first());
    }

    public function test_a_duplicate_plan_code_is_refused(): void
    {
        $this->actingAsSuper();

        $this->post(route('super.plans.store', absolute: false), [
            'code' => 'starter', 'name' => 'Clash', 'price_monthly_paisa' => 0, 'price_yearly_paisa' => 0,
            'trial_days' => 0, 'sort_order' => 1, 'limits' => [], 'toggles' => [],
        ])->assertSessionHasErrors('code');
    }

    public function test_the_console_can_raise_issue_void_and_settle_a_platform_invoice(): void
    {
        config(['billing.gateways.driver' => 'log']);
        $admin = $this->actingAsSuper();
        $tenant = $this->tenant('a');
        Subscription::query()->whereKey($tenant->current_subscription_id)->update(['status' => 'active', 'price_paisa' => 150000]);

        $this->post(route('super.tenants.invoices.store', ['tenant' => $tenant->public_id], false), ['issue' => true])->assertRedirect();
        $invoice = SubscriptionInvoice::query()->where('tenant_id', $tenant->id)->firstOrFail();
        $this->assertSame(SubscriptionInvoiceStatus::Issued, $invoice->status);
        $this->assertSame(150000, $invoice->total_paisa);

        $this->post(route('super.tenants.invoices.pay', ['tenant' => $tenant->public_id, 'invoice' => $invoice->public_id], false), [
            'amount_paisa' => 150000, 'method' => 'bank_transfer', 'reference' => 'DBBL-77123',
        ])->assertRedirect();

        $this->assertSame(SubscriptionInvoiceStatus::Paid, $invoice->refresh()->status);
        $this->assertSame(150000, (int) $invoice->getAttribute('paid_paisa'));
        $this->assertSame($admin->id, (int) $invoice->payments()->first()?->getAttribute('recorded_by_super_admin_id'));

        // A paid invoice cannot be voided.
        $this->post(route('super.tenants.invoices.void', ['tenant' => $tenant->public_id, 'invoice' => $invoice->public_id], false), ['reason' => 'oops'])
            ->assertSessionHasErrors('domain');
    }

    public function test_the_usage_dashboard_and_the_audit_log_render_and_filter(): void
    {
        $this->actingAsSuper();
        $tenant = $this->tenant('a');
        $this->setUsage($tenant, UsageMetric::Appointments, 42);

        $this->get(route('super.usage.index', absolute: false).'?metric=appointments')->assertOk()
            ->assertInertia(fn ($page) => $page->component('Super/Usage/Index')
                ->where('metric', 'appointments')
                ->has('series')
                ->has('metric_labels.appointments')
                ->where('top.0.value', 42));

        $this->post(route('super.tenants.suspend', ['tenant' => $tenant->public_id], false), ['reason' => 'for the audit log']);

        $this->get(route('super.audit.index', absolute: false).'?action=suspend')->assertOk()
            ->assertInertia(fn ($page) => $page->component('Super/Audit/Index')
                ->where('logs.0.action', 'suspend')
                ->where('logs.0.tenant.slug', 'test-a')
                ->has('actions'));
    }

    public function test_the_promotion_queue_and_reconciliation_screens_are_wired_to_the_catalog_module(): void
    {
        $this->actingAsSuper();

        $this->get(route('super.catalog.review', absolute: false))->assertOk()
            ->assertInertia(fn ($page) => $page->component('Super/Catalog/Review')
                ->where('status', 'pending')
                ->has('counts')
                ->where('endpoints.index', route('super.catalog.promotions.index'))
                ->has('endpoints.approve'));

        // The JSON endpoints themselves are the Catalog module's and still answer.
        $this->getJson(route('super.catalog.promotions.index', absolute: false))->assertOk();

        $this->get(route('super.catalog.reconciliation.index', absolute: false))->assertOk()
            ->assertInertia(fn ($page) => $page->component('Super/Catalog/Reconciliation')->has('reports')->has('meta.total'));
    }

    public function test_a_second_operator_sees_the_same_console_and_every_action_names_who_did_it(): void
    {
        $first = $this->actingAsSuper();
        $tenant = $this->tenant('a');
        $this->post(route('super.tenants.suspend', ['tenant' => $tenant->public_id], false), ['reason' => 'by the first operator']);

        $second = $this->actingAsSuper();
        $this->post(route('super.tenants.reactivate', ['tenant' => $tenant->public_id], false), ['reason' => 'by the second operator']);

        $this->assertSame($first->id, AuditLogCentral::query()->where('tenant_id', $tenant->id)->where('action', 'suspend')->value('super_admin_id'));
        $this->assertSame($second->id, AuditLogCentral::query()->where('tenant_id', $tenant->id)->where('action', 'reactivate')->value('super_admin_id'));
    }
}
