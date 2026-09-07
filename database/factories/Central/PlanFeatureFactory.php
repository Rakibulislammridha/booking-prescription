<?php

declare(strict_types=1);

namespace Database\Factories\Central;

use App\Domain\SaaS\Enums\PlanFeatureKey;
use App\Models\Central\Plan;
use App\Models\Central\PlanFeature;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PlanFeature> */
final class PlanFeatureFactory extends Factory
{
    protected $model = PlanFeature::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'plan_id' => Plan::factory(),
            'feature_key' => $this->faker->randomElement(PlanFeatureKey::values()),
            'limit_value' => null,
            'enabled' => true,
        ];
    }
}
