<?php

declare(strict_types=1);

namespace Database\Factories\Central;

use App\Domain\SaaS\Enums\BillingCycle;
use App\Domain\SaaS\Enums\SubscriptionStatus;
use App\Models\Central\Plan;
use App\Models\Central\Subscription;
use App\Models\Central\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Subscription> */
final class SubscriptionFactory extends Factory
{
    protected $model = Subscription::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'plan_id' => Plan::factory(),
            'status' => SubscriptionStatus::Trialing,
            'billing_cycle' => BillingCycle::Monthly,
            'price_paisa' => 150000,
            'current_period_start' => now(),
            'current_period_end' => now()->addDays(14),
            'trial_ends_at' => now()->addDays(14),
            'feature_overrides' => [],
        ];
    }

    public function active(): static
    {
        return $this->state(fn () => ['status' => SubscriptionStatus::Active, 'trial_ends_at' => null, 'current_period_end' => now()->addMonth()]);
    }
}
