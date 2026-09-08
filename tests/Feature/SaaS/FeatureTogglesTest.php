<?php

declare(strict_types=1);

namespace Tests\Feature\SaaS;

use App\Domain\Clinic\Enums\Role;
use App\Domain\SaaS\Actions\Plans\SavePlan;
use App\Domain\SaaS\Actions\Tenants\ToggleTenantFeature;
use App\Domain\SaaS\Data\PlanData;
use App\Domain\SaaS\Enums\PlanFeatureKey;
use App\Domain\SaaS\Services\FeatureFlagCache;
use App\Domain\SaaS\Services\PlanLimits;
use App\Models\Central\Plan;
use App\Models\Central\Subscription;
use App\Tenancy\Facades\Tenancy;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;
use Tests\Feature\SaaS\Concerns\ControlsPlanLimits;
use Tests\TestCase;

/**
 * Module toggles: what a plan includes has to be true in the TENANT app, not merely in a table — so these assert
 * through `Feature::active()` (which is what the Prescription module's `AiGate` already calls) and through the
 * shared props the client reads.
 */
final class FeatureTogglesTest extends TestCase
{
    use ControlsPlanLimits;

    public function test_a_plan_toggle_reaches_the_tenant_app_through_pennant_and_the_shared_props(): void
    {
        $tenant = $this->tenant('a');
        $this->setToggle($tenant, PlanFeatureKey::AiAssist, true);
        $this->setToggle($tenant, PlanFeatureKey::Telemedicine, false);

        $this->asTenant('a');
        $this->assertTrue(Feature::active('ai-assist'), 'the Prescription module asks for exactly this name');
        $this->assertFalse(Feature::active('telemedicine'));

        $this->actingAsStaff();
        $this->get('/panel')->assertOk()->assertInertia(fn ($page) => $page
            ->where('features.ai-assist', true)
            ->where('features.telemedicine', false)
            ->where('tenant.modules', ['ai-assist']));
    }

    public function test_turning_a_feature_off_takes_effect_on_the_next_request(): void
    {
        $tenant = $this->tenant('a');
        $this->setToggle($tenant, PlanFeatureKey::Whatsapp, true);

        $this->asTenant('a');
        $this->assertTrue(Feature::active('whatsapp'));

        Tenancy::end();
        app(ToggleTenantFeature::class)->handle($tenant, PlanFeatureKey::Whatsapp, false);

        $this->asTenant('a');
        $this->assertFalse(Feature::active('whatsapp'), 'the resolved value is a cache and must be purged when the entitlement moves');
    }

    public function test_resetting_an_override_falls_back_to_the_plan(): void
    {
        $tenant = $this->tenant('a');
        $plan = $this->planWith([PlanFeatureKey::Telemedicine->value => true]);
        Subscription::query()->whereKey($tenant->current_subscription_id)->update(['plan_id' => $plan->id]);
        app(ToggleTenantFeature::class)->handle($tenant, PlanFeatureKey::Telemedicine, false);

        $this->asTenant('a');
        $this->assertFalse(Feature::active('telemedicine'), 'the override wins over the plan');

        Tenancy::end();
        app(ToggleTenantFeature::class)->handle($tenant, PlanFeatureKey::Telemedicine, null);

        $this->asTenant('a');
        $this->assertTrue(Feature::active('telemedicine'), 'clearing the override returns to what the plan says');
    }

    public function test_editing_a_plan_changes_what_every_tenant_on_it_can_do(): void
    {
        $tenant = $this->tenant('a');
        $plan = $this->planWith([PlanFeatureKey::HandwritingMode->value => false]);
        Subscription::query()->whereKey($tenant->current_subscription_id)->update(['plan_id' => $plan->id, 'feature_overrides' => '{}']);
        app(FeatureFlagCache::class)->forTenant($tenant->refresh());

        $this->asTenant('a');
        $this->assertFalse(Feature::active('handwriting-mode'));

        Tenancy::end();
        app(SavePlan::class)->handle($this->planData($plan->code, [PlanFeatureKey::HandwritingMode->value => true]), $plan);

        $this->asTenant('a');
        $this->assertTrue(Feature::active('handwriting-mode'), 'a plan edit must purge the resolved rows of every tenant on it');
    }

    public function test_a_tenant_with_no_live_subscription_gets_no_paid_modules(): void
    {
        $tenant = $this->tenant('a');
        Subscription::query()->whereKey($tenant->current_subscription_id)->update(['status' => 'cancelled']);
        DB::table('public.tenants')->where('id', $tenant->id)->update(['current_subscription_id' => null]);
        app(FeatureFlagCache::class)->forTenant($tenant->refresh());

        $entitlements = app(PlanLimits::class)->entitlements($tenant->refresh());

        $this->assertFalse($entitlements->hasLiveSubscription);
        foreach (PlanFeatureKey::toggles() as $key) {
            $this->assertFalse($entitlements->enabled($key), "{$key->value} must not leak without a subscription");
        }
    }

    public function test_the_plan_middleware_refuses_a_module_the_plan_excludes(): void
    {
        $tenant = $this->tenant('a');
        $this->setToggle($tenant, PlanFeatureKey::CustomDomain, false);
        $this->asTenant('a');
        $this->actingAsStaff(Role::HospitalAdmin);

        // The tenant's own domain screen degrades rather than 403s, and says which plan is in force.
        $this->get('/panel/domains')->assertOk()->assertInertia(fn ($page) => $page
            ->component('SaaS/Domains')->where('allowed', false)->has('plan_name'));

        $this->post('/panel/domains', ['domain' => 'queue.example.com'])
            ->assertSessionHasErrors('domain');
    }

    /** @param  array<string, bool>  $toggles */
    private function planWith(array $toggles): Plan
    {
        return app(SavePlan::class)->handle($this->planData('toggle-test', $toggles));
    }

    /** @param  array<string, bool>  $toggles */
    private function planData(string $code, array $toggles): PlanData
    {
        return new PlanData(
            code: $code,
            name: 'Toggle test',
            description: null,
            priceMonthlyPaisa: 100000,
            priceYearlyPaisa: 1000000,
            trialDays: 0,
            isPublic: false,
            isAddon: false,
            sortOrder: 99,
            limits: [PlanFeatureKey::Doctors->value => null, PlanFeatureKey::Branches->value => null, PlanFeatureKey::AppointmentsMonthly->value => null, PlanFeatureKey::SmsCreditsMonthly->value => null, PlanFeatureKey::StorageBytes->value => null],
            toggles: $toggles,
        );
    }
}
