<?php

declare(strict_types=1);

namespace Database\Factories\Central;

use App\Models\Central\CustomBrandPromotion;
use App\Models\Central\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CustomBrandPromotion> */
final class CustomBrandPromotionFactory extends Factory
{
    protected $model = CustomBrandPromotion::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'custom_brand_id' => $this->faker->unique()->numberBetween(1, 100000),
            'brand_name' => ucfirst($this->faker->unique()->lexify('??????')),
            'manufacturer' => $this->faker->company(),
            'generic_id' => $this->faker->numberBetween(1, 5000),
            'generic_name' => 'Paracetamol',
            'snapshot' => ['strength' => '500 mg', 'dosage_form_id' => null, 'form' => 'Tablet', 'route_id' => null, 'route' => 'Oral', 'use_count' => 1, 'created_by_user_public_id' => null],
            'status' => 'pending',
            'submitted_at' => now(),
        ];
    }
}
