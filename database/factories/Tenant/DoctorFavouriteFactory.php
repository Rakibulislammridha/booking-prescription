<?php

declare(strict_types=1);

namespace Database\Factories\Tenant;

use App\Models\Tenant\Doctor;
use App\Models\Tenant\DoctorFavourite;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DoctorFavourite> */
final class DoctorFavouriteFactory extends Factory
{
    protected $model = DoctorFavourite::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'doctor_id' => Doctor::factory()->complete(),
            'icd10_code' => null,
            'generic_id' => $this->faker->unique()->numberBetween(100000, 999999),
            'brand_id' => null,
            'custom_brand_id' => null,
            'strength_id' => null,
            'label' => 'Napa 500 mg Tab',
            'default_dose' => ['dose_schedule' => '1+0+1', 'duration_days' => 5, 'timing' => 'after', 'instruction' => null, 'shorthand' => '1+0+1 5d af'],
            'is_pinned' => false,
            'use_count' => 1,
            'rank' => 0,
            'last_used_at' => now(),
        ];
    }

    public function pinned(): static
    {
        return $this->state(fn () => ['is_pinned' => true]);
    }
}
