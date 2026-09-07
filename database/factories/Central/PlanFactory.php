<?php

declare(strict_types=1);

namespace Database\Factories\Central;

use App\Models\Central\Plan;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Plan> */
final class PlanFactory extends Factory
{
    protected $model = Plan::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        $code = $this->faker->unique()->lexify('plan-????');

        return [
            'code' => $code,
            'name' => ucfirst($code),
            'description' => $this->faker->sentence(),
            'price_monthly_paisa' => 150000,
            'price_yearly_paisa' => 1500000,
            'trial_days' => 14,
            'is_public' => true,
            'is_addon' => false,
            'sort_order' => 0,
        ];
    }

    public function addon(): static
    {
        return $this->state(fn () => ['is_addon' => true, 'trial_days' => 0]);
    }
}
