<?php

declare(strict_types=1);

namespace Tests\Feature\SaaS;

use App\Domain\Audit\Enums\CentralAuditAction;
use App\Domain\SaaS\Enums\PlanFeatureKey;
use App\Domain\SaaS\Enums\SubscriptionStatus;
use App\Domain\SaaS\Enums\UsageMetric;
use App\Domain\SaaS\Services\PlanLimits;
use App\Models\Central\AuditLogCentral;
use App\Models\Central\Plan;
use App\Models\Central\PlanFeature;
use App\Models\Central\Subscription;
use App\Models\Central\SuperAdmin;
use App\Tenancy\Facades\Tenancy;
use Tests\Feature\SaaS\Concerns\ControlsPlanLimits;
use Tests\TestCase;

/**
 * Plan management from the console (BRIEF §5.M "plan tiers with enforced limits"): the editor round-trips every
 * feature key in integer paisa and bytes with no float anywhere, the pricing page reflects the edit on the next
 * request, a plan that clinics are on must be acknowledged before it changes under them, archiving is guarded
 * by the subscriber count, cloning makes a hidden copy — and every one of those is an audit row.
 */
final class PlanManagementTest extends TestCase
{
    use ControlsPlanLimits;

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function payload(array $overrides = []): array
    {
        return array_replace([
            'code' => 'clinic-plus',
            'name' => 'Clinic Plus',
            'description' => 'Three branches, ten doctors.',
            'price_monthly_paisa' => 250050,               // ৳2,500.50 — the paisa half proves nothing rounds
            'price_yearly_paisa' => 2500500,
            'trial_days' => 7,
            'is_public' => true,
            'is_addon' => false,
            'sort_order' => 5,
            'limits' => ['branches' => 3, 'doctors' => 10, 'appointments_monthly' => null, 'sms_credits_monthly' => 2000, 'storage_bytes' => 5 * 1024 * 1024 * 1024],
            'toggles' => ['whatsapp' => true, 'telemedicine' => false, 'reports_export' => true],
        ], $overrides);
    }

    public function test_the_list_and_the_editor_render_with_subscriber_counts_and_every_feature_key(): void
    {
        $this->actingAsSuper();

        $this->get(route('super.plans.index', absolute: false))->assertOk()
            ->assertInertia(fn ($page) => $page->component('Super/Plans/Index')
                ->has('plans')
                ->has('subscriptions.starter')
                ->where('limit_keys', array_map(fn (PlanFeatureKey $k) => $k->value, PlanFeatureKey::limits()))
                ->where('toggle_keys', array_map(fn (PlanFeatureKey $k) => $k->value, PlanFeatureKey::toggles())));

        $this->get(route('super.plans.create', absolute: false))->assertOk()
            ->assertInertia(fn ($page) => $page->component('Super/Plans/Edit')->where('plan', null)->where('subscribers', 0)->has('next_sort_order'));

        // The fixture tenants live on `starter`, so its editor says so.
        $this->get(route('super.plans.edit', ['plan' => 'starter'], false))->assertOk()
            ->assertInertia(fn ($page) => $page->component('Super/Plans/Edit')
                ->where('plan.code', 'starter')
                ->has('plan.limits.0.key')
                ->where('subscribers', fn ($n) => (int) $n >= 1));
    }

    public function test_creating_a_plan_round_trips_paisa_and_bytes_as_integers_and_the_pricing_page_shows_it(): void
    {
        $admin = $this->actingAsSuper();

        $this->post(route('super.plans.store', absolute: false), $this->payload())->assertRedirect(route('super.plans.index', absolute: false));

        $plan = Plan::query()->where('code', 'clinic-plus')->firstOrFail();
        $this->assertSame(250050, $plan->price_monthly_paisa);
        $this->assertSame(2500500, $plan->price_yearly_paisa);
        $this->assertIsInt($plan->price_monthly_paisa);
        $this->assertSame(5 * 1024 * 1024 * 1024, (int) PlanFeature::query()->where('plan_id', $plan->id)->where('feature_key', 'storage_bytes')->value('limit_value'));
        $this->assertSame(2000, (int) PlanFeature::query()->where('plan_id', $plan->id)->where('feature_key', 'sms_credits_monthly')->value('limit_value'));
        $this->assertNull(PlanFeature::query()->where('plan_id', $plan->id)->where('feature_key', 'appointments_monthly')->value('limit_value'), 'unlimited is null, never 0');
        $this->assertTrue((bool) PlanFeature::query()->where('plan_id', $plan->id)->where('feature_key', 'reports_export')->value('enabled'));

        // The public pricing page reads the same rows.
        $this->asCentral()->withServerVariables(['HTTP_HOST' => 'bp.test']);
        /** @var array<string, mixed> $shown */
        $shown = collect($this->pricingPlans())->firstWhere('code', 'clinic-plus');
        $this->assertSame(250050, $shown['price_monthly_paisa']);
        /** @var array<int, array<string, mixed>> $limits */
        $limits = $shown['limits'];
        /** @var array<int, array<string, mixed>> $toggles */
        $toggles = $shown['toggles'];
        $this->assertSame(3, collect($limits)->firstWhere('key', 'branches')['value']);
        $this->assertTrue(collect($toggles)->firstWhere('key', 'whatsapp')['enabled']);

        // Audited under the operator's name.
        $log = AuditLogCentral::query()->where('action', CentralAuditAction::Create->value)->where('auditable_id', $plan->id)->latest('id')->firstOrFail();
        $this->assertSame($admin->id, (int) $log->getAttribute('super_admin_id'));
        $this->assertSame(250050, $log->getAttribute('after')['price_monthly_paisa']);
    }

    public function test_editing_a_plan_with_live_subscribers_needs_an_acknowledgement_then_changes_entitlements_now_and_price_later(): void
    {
        $this->actingAsSuper();
        $tenant = $this->tenant('a');
        $subscription = Subscription::query()->findOrFail($tenant->current_subscription_id);
        $plan = Plan::query()->findOrFail($subscription->plan_id);
        $priceBefore = $subscription->price_paisa;

        // Clear the fixture's unlimited overrides so the plan's own rows are what the tenant gets.
        $subscription->forceFill(['feature_overrides' => []])->save();
        $this->flushEntitlements($tenant);

        $payload = $this->payload([
            'code' => $plan->code, 'name' => $plan->name, 'sort_order' => $plan->sort_order,
            'price_monthly_paisa' => $plan->price_monthly_paisa + 100000,
            'limits' => ['branches' => 7, 'doctors' => 4],
            'toggles' => ['telemedicine' => true],
        ]);

        // Without the acknowledgement the request is refused and nothing changes.
        $this->put(route('super.plans.update', ['plan' => $plan->code], false), $payload)->assertSessionHasErrors('acknowledge');
        $this->assertNotSame(7, app(PlanLimits::class)->limit($tenant->refresh(), UsageMetric::Branches));

        $this->put(route('super.plans.update', ['plan' => $plan->code], false), $payload + ['acknowledge' => true])->assertRedirect();
        $this->flushEntitlements($tenant);

        $this->assertSame(7, app(PlanLimits::class)->limit($tenant->refresh(), UsageMetric::Branches), 'entitlements change immediately');
        $this->assertTrue(app(PlanLimits::class)->enabled($tenant, PlanFeatureKey::Telemedicine));
        $this->assertSame($priceBefore, $subscription->refresh()->price_paisa, 'the locked price changes at the next renewal, never mid-period');
        $this->assertSame($plan->price_monthly_paisa + 100000, $plan->refresh()->price_monthly_paisa);
        $this->assertTrue(AuditLogCentral::query()->where('action', CentralAuditAction::Update->value)->where('auditable_id', $plan->id)->exists());
    }

    public function test_archiving_a_plan_with_subscribers_is_guarded_and_archiving_hides_it_from_pricing_until_restored(): void
    {
        $this->actingAsSuper();
        $pro = Plan::query()->where('code', 'pro')->firstOrFail();

        // Put a live subscriber on Pro.
        $subscription = Subscription::query()->findOrFail($this->tenant('b')->current_subscription_id);
        $subscription->forceFill(['plan_id' => $pro->id, 'status' => SubscriptionStatus::Active])->save();

        $this->delete(route('super.plans.destroy', ['plan' => 'pro'], false))->assertSessionHasErrors('domain');
        $this->assertNull($pro->refresh()->archived_at, 'the guard held');

        $this->delete(route('super.plans.destroy', ['plan' => 'pro'], false), ['confirm' => true])->assertRedirect();
        $this->assertNotNull($pro->refresh()->archived_at);
        $this->assertFalse($pro->is_public);
        $this->assertSame($pro->id, $subscription->refresh()->plan_id, 'the clinic keeps its plan');

        $this->asCentral()->withServerVariables(['HTTP_HOST' => 'bp.test']);
        $codes = collect($this->pricingPlans())->pluck('code')->all();
        $this->assertNotContains('pro', $codes);

        $this->actingAsSuper();
        $this->delete(route('super.plans.destroy', ['plan' => 'pro'], false), ['restore' => true])->assertRedirect();
        $this->assertNull($pro->refresh()->archived_at);

        // A plan nobody is on archives without ceremony.
        $this->post(route('super.plans.store', absolute: false), $this->payload(['code' => 'lonely', 'name' => 'Lonely']))->assertRedirect();
        $this->delete(route('super.plans.destroy', ['plan' => 'lonely'], false))->assertRedirect()->assertSessionHasNoErrors();
        $this->assertNotNull(Plan::query()->where('code', 'lonely')->firstOrFail()->archived_at);
    }

    public function test_cloning_a_plan_makes_a_hidden_copy_with_every_feature_row_and_lands_in_its_editor(): void
    {
        $admin = $this->actingAsSuper();
        $pro = Plan::query()->where('code', 'pro')->firstOrFail();
        $features = PlanFeature::query()->where('plan_id', $pro->id)->count();

        $this->post(route('super.plans.clone', ['plan' => 'pro'], false))
            ->assertRedirect(route('super.plans.edit', ['plan' => 'pro-copy'], false));

        $copy = Plan::query()->where('code', 'pro-copy')->firstOrFail();
        $this->assertFalse($copy->is_public, 'a half-edited copy must never reach the pricing page');
        $this->assertSame($pro->price_monthly_paisa, $copy->price_monthly_paisa);
        $this->assertSame($features, PlanFeature::query()->where('plan_id', $copy->id)->count());
        $this->assertSame(
            PlanFeature::query()->where('plan_id', $pro->id)->orderBy('feature_key')->get(['feature_key', 'limit_value', 'enabled'])->toArray(),
            PlanFeature::query()->where('plan_id', $copy->id)->orderBy('feature_key')->get(['feature_key', 'limit_value', 'enabled'])->toArray(),
        );

        // A second clone gets the next free code.
        $this->post(route('super.plans.clone', ['plan' => 'pro'], false))->assertRedirect(route('super.plans.edit', ['plan' => 'pro-copy-2'], false));

        $log = AuditLogCentral::query()->where('action', CentralAuditAction::Create->value)->where('auditable_id', $copy->id)->firstOrFail();
        $this->assertSame($admin->id, (int) $log->getAttribute('super_admin_id'));
        $this->assertSame('pro', $log->getAttribute('before')['cloned_from']);

        $this->asCentral()->withServerVariables(['HTTP_HOST' => 'bp.test']);
        $this->assertNotContains('pro-copy', collect($this->pricingPlans())->pluck('code')->all());
    }

    public function test_plan_screens_leave_no_tenancy_behind_and_are_closed_to_guests(): void
    {
        $this->actingAsSuper();

        foreach ([route('super.plans.index', absolute: false), route('super.plans.create', absolute: false), route('super.plans.edit', ['plan' => 'pro'], false)] as $url) {
            $this->get($url)->assertOk();
            $this->assertFalse(Tenancy::check(), "{$url} left a tenancy initialised");
        }

        $this->asCentral();
        auth('super')->logout();
        $this->get(route('super.plans.create', absolute: false))->assertRedirect(route('super.login', absolute: false));
        $this->post(route('super.plans.clone', ['plan' => 'pro'], false))->assertRedirect(route('super.login', absolute: false));
        $this->assertSame(0, Plan::query()->where('code', 'pro-copy')->count());
        $this->assertInstanceOf(SuperAdmin::class, SuperAdmin::query()->first());
    }

    /**
     * The public pricing page's plan rows, as the site bundle receives them.
     *
     * @return array<int, array<string, mixed>>
     */
    private function pricingPlans(): array
    {
        /** @var array<int, array<string, mixed>> $plans */
        $plans = (array) $this->withHeaders($this->inertiaHeaders())->get('/pricing')->json('props.plans');

        return $plans;
    }
}
