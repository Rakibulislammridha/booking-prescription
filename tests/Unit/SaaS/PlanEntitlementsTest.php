<?php

declare(strict_types=1);

namespace Tests\Unit\SaaS;

use App\Domain\SaaS\Data\LimitStatus;
use App\Domain\SaaS\Data\PlanEntitlements;
use App\Domain\SaaS\Enums\PlanFeatureKey;
use App\Domain\SaaS\Enums\UsageMetric;
use PHPUnit\Framework\TestCase;

/** The entitlement value object and the limit-status arithmetic the dashboards render. */
final class PlanEntitlementsTest extends TestCase
{
    public function test_an_unmentioned_limit_is_unlimited_and_an_unmentioned_module_is_off(): void
    {
        $entitlements = new PlanEntitlements('basic', 'Basic', ['doctors' => 10], ['whatsapp' => true]);

        $this->assertSame(10, $entitlements->limit(PlanFeatureKey::Doctors));
        $this->assertNull($entitlements->limit(PlanFeatureKey::Branches), 'a plan opts INTO caps');
        $this->assertTrue($entitlements->enabled(PlanFeatureKey::Whatsapp));
        $this->assertFalse($entitlements->enabled(PlanFeatureKey::Telemedicine), 'a plan opts INTO modules');
    }

    public function test_a_metric_resolves_through_its_plan_feature_key(): void
    {
        $entitlements = new PlanEntitlements('basic', 'Basic', ['appointments_monthly' => 3000], []);

        $this->assertSame(3000, $entitlements->limitFor(UsageMetric::Appointments));
        $this->assertNull($entitlements->limitFor(UsageMetric::Prescriptions), 'an uncapped metric has no key');
    }

    public function test_no_subscription_means_no_paid_module_but_no_arbitrary_cap(): void
    {
        $none = PlanEntitlements::none();

        $this->assertFalse($none->hasLiveSubscription);
        foreach (PlanFeatureKey::toggles() as $key) {
            $this->assertFalse($none->enabled($key));
        }
        foreach (PlanFeatureKey::limits() as $key) {
            $this->assertNull($none->limit($key));
        }
    }

    public function test_the_feature_map_is_keyed_by_the_pennant_names_the_app_asks_for(): void
    {
        $map = (new PlanEntitlements('pro', 'Pro', [], ['ai_assist' => true]))->featureMap();

        $this->assertArrayHasKey('ai-assist', $map, 'App\\Domain\\Prescription\\AI\\AiGate asks for `ai-assist`');
        $this->assertArrayHasKey('waiting-room-display', $map);
        $this->assertTrue($map['ai-assist']);
        $this->assertFalse($map['telemedicine']);
        $this->assertArrayNotHasKey('doctors', $map, 'numeric caps are not Pennant features');
    }

    public function test_the_enum_maps_every_metric_and_key_in_both_directions(): void
    {
        foreach (PlanFeatureKey::limits() as $key) {
            $metric = $key->metric();
            $this->assertNotNull($metric);
            $this->assertSame($key, $metric->limitKey());
            $this->assertNull($key->featureName());
        }

        foreach (PlanFeatureKey::toggles() as $key) {
            $this->assertNull($key->metric());
            $this->assertSame($key, PlanFeatureKey::fromFeatureName((string) $key->featureName()));
        }

        $this->assertTrue(UsageMetric::Doctors->isGauge());
        $this->assertFalse(UsageMetric::Appointments->isGauge());
        $this->assertTrue(UsageMetric::StorageBytes->isBytes());
    }

    public function test_limit_status_arithmetic(): void
    {
        $unlimited = new LimitStatus(UsageMetric::Doctors, 40, null, 'current');
        $this->assertTrue($unlimited->isUnlimited());
        $this->assertNull($unlimited->remaining());
        $this->assertNull($unlimited->percent());
        $this->assertFalse($unlimited->isExhausted());

        $half = new LimitStatus(UsageMetric::Appointments, 250, 500, '2026-04');
        $this->assertSame(250, $half->remaining());
        $this->assertSame(50, $half->percent());
        $this->assertFalse($half->isExhausted());

        $full = new LimitStatus(UsageMetric::Appointments, 500, 500, '2026-04');
        $this->assertSame(0, $full->remaining());
        $this->assertSame(100, $full->percent());
        $this->assertTrue($full->isExhausted());

        $over = new LimitStatus(UsageMetric::Appointments, 620, 500, '2026-04');
        $this->assertSame(0, $over->remaining(), 'over the cap reads as zero remaining, never negative');
        $this->assertSame(100, $over->percent(), 'the bar cannot go past the end');

        $disabled = new LimitStatus(UsageMetric::Branches, 0, 0, 'current');
        $this->assertSame(100, $disabled->percent(), 'a zero cap is exhausted by definition, not a division by zero');
        $this->assertTrue($disabled->isExhausted());
    }
}
